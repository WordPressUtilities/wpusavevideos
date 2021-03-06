<?php

/*
Plugin Name: WPU Save Videos
Plugin URI: http://github.com/Darklg/WPUtilities
Description: Save Videos thumbnails.
Version: 0.12.1
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUSaveVideos {

    private $plugin_version = '0.12.1';
    private $saved_posts = array();
    private $hosts = array(
        'youtube' => array(
            'youtu.be',
            'youtube.com',
            'www.youtube.com'
        ),
        'vimeo' => array(
            'vimeo.com',
            'www.vimeo.com',
            'player.vimeo.com'
        ),
        'dailymotion' => array(
            'dailymotion.com',
            'www.dailymotion.com'
        )
    );

    private $no_save_posttypes = array(
        'revision',
        'attachment'
    );

    public function __construct() {
        add_action('save_post', array(&$this,
            'save_post'
        ), 10, 3);
        add_action('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        if (apply_filters('wpusavevideos_enable_oembed_player', false)) {
            add_action('wp_enqueue_scripts', array(&$this,
                'load_assets'
            ));
            add_filter('embed_oembed_html', array(&$this,
                'embed_oembed_html'
            ), 99, 4);
        }
    }

    public function plugins_loaded() {

        /* Updater */
        include dirname(__FILE__) . '/inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpusavevideos\WPUBaseUpdate(
            'WordPressUtilities',
            'wpusavevideos',
            $this->plugin_version);
    }

    public function save_post($post_id, $post) {
        if (!is_object($post)) {
            return;
        }

        if (!is_numeric($post_id)) {
            return;
        }

        if (in_array($post_id, $this->saved_posts)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (in_array($post->post_type, $this->no_save_posttypes)) {
            return;
        }

        $this->saved_posts[] = $post_id;

        /* Get current video list */
        $videos = get_post_meta($post_id, 'wpusavevideos_videos', 1);

        if (!is_array($videos)) {
            $videos = unserialize($videos);
        }
        if (!is_array($videos)) {
            $videos = array();
        }

        /* Add new videos  */
        $new_videos = $this->extract_videos_from_text($post->post_content);
        $metas = apply_filters('wpusavevideos_metas_to_parse', array());
        foreach ($metas as $meta) {
            $new_videos_tmp = $this->extract_videos_from_text(get_post_meta($post_id, $meta, 1));
            $new_videos = array_merge($new_videos, $new_videos_tmp);
        }

        /* Extract video details */
        foreach ($new_videos as $id => $new_video) {
            if (!array_key_exists($id, $videos)) {
                $new_video['thumbnail'] = $this->retrieve_thumbnail($new_video['url'], $post_id);
                if ($new_video['thumbnail'] !== false) {
                    $videos[$id] = $new_video;
                }
            }
        }

        /* Remove inexistant videos */
        $saved_videos = array();
        foreach ($videos as $id => $video) {
            if (array_key_exists($id, $new_videos) || (isset($video['forced_url']) && $video['forced_url'])) {
                $saved_videos[$id] = $video;
            }
        }

        /* Set post thumbnail */
        if (apply_filters('wpusavevideos_set_post_thumbnail', false) && !has_post_thumbnail($post_id)) {
            foreach ($saved_videos as $video) {
                if (isset($video['thumbnail']) && is_numeric($video['thumbnail'])) {
                    set_post_thumbnail($post_id, $video['thumbnail']);
                    break;
                }
            }
        }

        /* Save video list */
        update_post_meta($post_id, 'wpusavevideos_videos', $saved_videos);
    }

    public function extract_videos_from_text($text) {

        $hosts = array();
        foreach ($this->hosts as $new_hosts) {
            $hosts = array_merge($hosts, $new_hosts);
        }

        $videos = array();
        $urls = wp_extract_urls($text);
        foreach ($urls as $url) {

            // Get URL Key
            $url_key = md5($url);
            $url_parsed = parse_url($url);

            // No valid host
            if (!isset($url_parsed['host'])) {
                continue;
            }

            // Test host
            if (in_array($url_parsed['host'], $hosts)) {
                $videos[$url_key] = array(
                    'url' => $url
                );
            }
        }

        return $videos;
    }

    public function retrieve_thumbnail($video_url, $post_id) {

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $thumbnail_details = $this->retrieve_thumbnail_details($video_url);

        if (is_array($thumbnail_details)) {
            $thumb_url = media_sideload_image($thumbnail_details['url'], $post_id, $thumbnail_details['title'], 'src');
            if (is_object($thumb_url) && isset($thumbnail_details['urlalt'])) {
                $thumb_url = media_sideload_image($thumbnail_details['urlalt'], $post_id, $thumbnail_details['title'], 'src');
            }
            if (is_object($thumb_url)) {
                return false;
            }
            $thumb_id = $this->get_attachment_id_from_src($thumb_url);
            if ($thumbnail_details['width'] > 0 && $thumbnail_details['height'] > 0) {
                $percent_ratio = 100 / (intval($thumbnail_details['width']) / intval($thumbnail_details['height']));
                add_post_meta($thumb_id, 'wpusavevideos_ratio', $percent_ratio);
            }
            return $thumb_id;
        }

        return false;
    }

    public function retrieve_thumbnail_details($video_url) {

        $url_parsed = parse_url($video_url);

        if (!isset($url_parsed['host'])) {
            return '';
        }

        // Extract for youtube
        if (in_array($url_parsed['host'], $this->hosts['youtube'])) {
            $tmp_return = $this->get_yt_details($video_url);
            if (is_array($tmp_return)) {
                return $tmp_return;
            }
        }

        // Extract for vimeo
        if (in_array($url_parsed['host'], $this->hosts['vimeo'])) {
            $tmp_return = $this->get_vimeo_details($video_url);
            if (is_array($tmp_return)) {
                return $tmp_return;
            }
        }

        // Extract for dailymotion
        if (in_array($url_parsed['host'], $this->hosts['dailymotion'])) {
            $tmp_return = $this->get_daily_details($video_url);
            if (is_array($tmp_return)) {
                return $tmp_return;
            }
        }

        return '';
    }

    public function get_yt_details($url) {
        $youtube_id = $this->parse_yturl($url);
        if (!$youtube_id) {
            return false;
        }

        // Default API
        $return_values = array(
            'url' => 'https://img.youtube.com/vi/' . $youtube_id . '/maxresdefault.jpg',
            'urlalt' => 'https://img.youtube.com/vi/' . $youtube_id . '/sddefault.jpg',
            'title' => $youtube_id,
            'width' => 0,
            'height' => 0
        );

        // Weird API
        $youtube_response = wp_remote_get('https://www.youtube.com/get_video_info?video_id=' . $youtube_id);
        parse_str(wp_remote_retrieve_body($youtube_response), $youtube_details);

        if (!is_array($youtube_details)) {
            $youtube_details = array();
        }

        // Try to get the title
        if (isset($youtube_details['title'])) {
            $return_values['title'] = $youtube_details['title'];
        }

        // Old thumbnail format
        if (isset($youtube_details['iurlhq'])) {
            $return_values['url'] = $youtube_details['iurlhq'];
            if (isset($youtube_details['iurlmaxres'])) {
                $return_values['url'] = $youtube_details['iurlmaxres'];
            }
        }

        // Try to retrieve the video dimensions
        if (isset($youtube_details['fmt_list'])) {
            $fmt_list = explode('/', $youtube_details['fmt_list']);
            foreach ($fmt_list as $fmt_info) {
                $fmt_info_details = explode('x', $fmt_info);
                if (is_array($fmt_info_details) && isset($fmt_info_details[1])) {
                    $return_values['width'] = $fmt_info_details[0];
                    $return_values['height'] = $fmt_info_details[1];
                    break;
                }
            }
        }

        return $return_values;

    }

    public function get_vimeo_details($url) {
        $vimeo_id = $this->parse_vimeourl($url);
        $vimeo_details = array();
        if (is_numeric($vimeo_id)) {
            $vimeo_response = wp_remote_get("https://vimeo.com/api/v2/video/" . $vimeo_id . ".json");
            $vimeo_details = json_decode(wp_remote_retrieve_body($vimeo_response));
        }

        if (isset($vimeo_details[0], $vimeo_details[0]->thumbnail_large)) {
            return array(
                'url' => $vimeo_details[0]->thumbnail_large,
                'title' => $vimeo_details[0]->title,
                'width' => $vimeo_details[0]->width,
                'height' => $vimeo_details[0]->height
            );
        }

        return false;
    }

    public function get_daily_details($url) {
        $daily_id = $this->parse_dailyurl($url);
        $daily_details = array();

        if (!empty($daily_id)) {
            $daily_response = wp_remote_get("https://api.dailymotion.com/video/" . $daily_id . "?fields=thumbnail_720_url,title,aspect_ratio");
            $daily_details = json_decode(wp_remote_retrieve_body($daily_response));
        }

        if (is_object($daily_details) && isset($daily_details->thumbnail_720_url)) {

            $width = 0;
            $height = 0;

            // Try to retrieve the video dimensions through the aspect ratio
            if (isset($daily_details->aspect_ratio)) {
                $height = 300;
                $width = intval(floor($height * $daily_details->aspect_ratio));
            }

            return array(
                'url' => $daily_details->thumbnail_720_url,
                'title' => $daily_details->title,
                'width' => $width,
                'height' => $height
            );
        }

        return false;
    }

    /* ----------------------------------------------------------
      Parse URLs
    ---------------------------------------------------------- */

    /**
     *  Check if input string is a valid YouTube URL
     *  and try to extract the YouTube Video ID from it.
     *  @author  Stephan Schmitz <eyecatchup@gmail.com>
     *  @param   $url   string   The string that shall be checked.
     *  @return  mixed           Returns YouTube Video ID, or (boolean) false.
     */
    public function parse_yturl($url) {
        $pattern = '#^(?:https?://|//)?(?:www\.|m\.)?(?:youtu\.be/|youtube\.com/(?:embed/|v/|watch\?v=|watch\?.+&v=))([\w-]{11})(?![\w-])#';
        preg_match($pattern, $url, $matches);
        return (isset($matches[1])) ? $matches[1] : false;
    }

    public function parse_dailyurl($url) {
        $url_parsed = parse_url($url);
        return strtok(basename($url_parsed['path']), '_');
    }

    public function parse_vimeourl($url) {
        $url_parsed = parse_url($url);
        $vimeo_url = explode('/', $url_parsed['path']);
        $vimeo_id = false;
        foreach ($vimeo_url as $url_part) {
            if (is_numeric($url_part)) {
                $vimeo_id = $url_part;
            }
        }
        return $vimeo_id;
    }

    /* ----------------------------------------------------------
      Oembed lite player
    ---------------------------------------------------------- */

    public function load_assets() {
        wp_enqueue_script('wpusavevideo_oembed_script', plugins_url('assets/script.js', __FILE__), array(
            'jquery'
        ), $this->plugin_version);
        wp_register_style('wpusavevideo_oembed_style', plugins_url('assets/style.css', __FILE__), array(), $this->plugin_version);
        wp_enqueue_style('wpusavevideo_oembed_style');
    }

    public function embed_oembed_html($html, $url, $attr, $post_id) {
        if (is_admin()) {
            return $html;
        }
        $wpusavevideos_videos = get_post_meta($post_id, 'wpusavevideos_videos', 1);
        if (!is_array($wpusavevideos_videos)) {
            $wpusavevideos_videos = unserialize($wpusavevideos_videos);
        }
        foreach ($wpusavevideos_videos as $video_url) {
            if ($video_url['url'] != $url) {
                continue;
            }
            preg_match('/src="(.*)"/isU', $html, $matches);
            if (!isset($matches[1])) {
                continue;
            }
            $embed_url = $matches[1];
            $image = wp_get_attachment_image_src($video_url['thumbnail'], 'full');
            if (!isset($image[0])) {
                continue;
            }
            $parse_url = parse_url($url);
            if (in_array($parse_url['host'], $this->hosts['youtube'])) {
                $embed_url .= '&autoplay=1';
            }
            if (in_array($parse_url['host'], $this->hosts['vimeo'])) {
                $embed_url .= '?autoplay=1';
            }
            if (in_array($parse_url['host'], $this->hosts['dailymotion'])) {
                $embed_url .= '?autoplay=1';
            }
            $style = '';
            $ratio = get_post_meta($video_url['thumbnail'], 'wpusavevideos_ratio', 1);

            // Only common values ( more than 1 digit )
            if (strlen($ratio) >= 2) {
                $style = 'padding-top:' . $ratio . '%;';
            }

            return '<div class="wpusv-embed-video" data-embed="' . $embed_url . '" style="' . $style . '">' . '<span class="cover" style="background-image:url(' . $image[0] . ');" >' . '<button class="wpusv-embed-video-play"></button>' . '</span>' . '</div>';
        }

        return $html;
    }

    /* ----------------------------------------------------------
      Utilities
    ---------------------------------------------------------- */

    /**
     * Get attachment ID from src
     * http://wordpress.stackexchange.com/a/227175
     * @param  string $image_src    url of the image
     * @return mixed                thumb id
     */
    public function get_attachment_id_from_src($image_src) {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE guid=%s", $image_src));
        return $id;
    }

    /* ----------------------------------------------------------
      Uninstall
    ---------------------------------------------------------- */

    public function uninstall() {
        delete_post_meta_by_key('wpusavevideos_videos');
        delete_post_meta_by_key('wpusavevideos_ratio');
    }
}

$WPUSaveVideos = new WPUSaveVideos();

/* ----------------------------------------------------------
  Helpers
---------------------------------------------------------- */

/**
 * Returns the thumbnail ID for a video
 * @param  string  $video_url        URL of the video
 * @param  boolean $post_id          Post where this video is saved
 * @param  boolean $force_download   Force URL download if not in post images
 * @return mixed                     ID of the thumbnail if ok, FALSE if error
 */
function wpusavevideos_get_video_thumbnail($video_url, $post_id = false, $force_download = false) {

    if (!$post_id) {
        $post_id = get_the_ID();
    }
    $video_thumbnails = get_post_meta($post_id, 'wpusavevideos_videos', 1);
    if (!$video_thumbnails && !$force_download) {
        return false;
    }
    if (!is_array($video_thumbnails)) {
        $video_thumbnails = unserialize($video_thumbnails);
    }
    if (!is_array($video_thumbnails)) {
        $video_thumbnails = array();
    }
    $video_thumbnail = false;
    foreach ($video_thumbnails as $thumbnail) {
        if ($thumbnail['url'] == $video_url) {
            $video_thumbnail = $thumbnail['thumbnail'];
        }
    }
    if (!$video_thumbnail) {
        if (!$force_download) {
            return false;
        }

        /* Forcing download */
        global $WPUSaveVideos;
        $video_thumbnail = $WPUSaveVideos->retrieve_thumbnail($video_url, $post_id);
        if (!is_numeric($video_thumbnail)) {
            error_log('Thumbnail could not be saved');
            return false;
        }

        /* Saving thumbnail ID */
        $video_thumbnails[md5($video_url)] = array(
            'url' => $video_url,
            'thumbnail' => $video_thumbnail,
            'forced_url' => 1
        );
        update_post_meta($post_id, 'wpusavevideos_videos', $video_thumbnails);

    }
    return $video_thumbnail;
}
