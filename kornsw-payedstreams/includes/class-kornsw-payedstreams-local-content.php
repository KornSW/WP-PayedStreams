<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Local_Content
{
    private $Repository;

    public function __construct($Repository)
    {
        $this->Repository = $Repository;
    }

    public function Sync()
    {
        $Count = 0;
        $this->Repository->DeleteVideosBySource('wp_media');
        $this->Repository->DeleteVideosBySource('local_file');
        $this->Repository->DeleteVideosBySource('post');
        if (get_option('kornsw_ps_local_wp_media_enabled', '0') === '1') {
            $Count += $this->SyncWordPressMedia();
        }
        $Count += $this->SyncFolderVideos();
        $Count += $this->SyncPosts();
        return $Count;
    }

    public function SyncWordPressMedia()
    {
        $Attachments = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'video',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ));
        $Count = 0;
        foreach ($Attachments as $Attachment) {
            $Url = wp_get_attachment_url($Attachment->ID);
            if (!$Url) { continue; }
            $Meta = wp_get_attachment_metadata($Attachment->ID);
            $Duration = is_array($Meta) && isset($Meta['length']) ? intval($Meta['length']) : 0;
            $Thumbnail = wp_get_attachment_image_url($Attachment->ID, 'medium_large');
            $this->Repository->UpsertVideo(array(
                'external_id' => 'wpmedia-' . intval($Attachment->ID),
                'title' => get_the_title($Attachment->ID),
                'thumbnail_url' => $Thumbnail ? $Thumbnail : '',
                'duration_seconds' => $Duration,
                'status' => 'available',
                'source_type' => 'wp_media',
                'source_ref' => (string)$Attachment->ID,
                'content_url' => $Url,
                'post_url' => '',
                'raw' => array('attachment_id' => intval($Attachment->ID), 'mime_type' => get_post_mime_type($Attachment->ID)),
            ));
            $Count++;
        }
        return $Count;
    }

    public function SyncFolderVideos()
    {
        $Relative = trim((string)get_option('kornsw_ps_local_video_folder', 'uploads/payedstreams'));
        if ($Relative === '') { return 0; }
        $Relative = trim(str_replace('\\', '/', $Relative), '/');
        if (strpos($Relative, '..') !== false) {
            KornSW_PayedStreams_Logger::Log('WARNING', 'Lokale Inhalte', 'Video-Ordner enthält unzulässige ..-Segmente.');
            return 0;
        }
        $Base = wp_normalize_path(WP_CONTENT_DIR);
        $Folder = wp_normalize_path($Base . '/' . $Relative);
        if (!is_dir($Folder)) {
            if (!wp_mkdir_p($Folder)) {
                KornSW_PayedStreams_Logger::Log('WARNING', 'Lokale Inhalte', 'Konfigurierter Video-Ordner existiert nicht und konnte nicht angelegt werden.', array('folder' => $Folder));
            }
            return 0;
        }
        $Allowed = array('mp4','m4v','webm','ogv','ogg','mov');
        $Files = scandir($Folder);
        if (!is_array($Files)) { return 0; }
        $BaseUrl = content_url('/' . $Relative . '/');
        $Count = 0;
        foreach ($Files as $File) {
            if ($File === '.' || $File === '..') { continue; }
            $Full = $Folder . '/' . $File;
            if (!is_file($Full)) { continue; }
            $Ext = strtolower(pathinfo($File, PATHINFO_EXTENSION));
            if (!in_array($Ext, $Allowed, true)) { continue; }
            $Id = 'localfile-' . substr(hash('sha256', $Relative . '/' . $File), 0, 24);
            $BaseName = pathinfo($File, PATHINFO_FILENAME);
            $Title = trim(str_replace(array('-', '_'), ' ', $BaseName));
            $ThumbnailUrl = '';
            $ThumbnailCandidates = array($BaseName . '.jpg', $BaseName . '.JPG', $BaseName . '.jpeg', $BaseName . '.JPEG');
            foreach ($ThumbnailCandidates as $ThumbnailFile) {
                if (is_file($Folder . '/' . $ThumbnailFile)) {
                    $ThumbnailUrl = trailingslashit($BaseUrl) . rawurlencode($ThumbnailFile);
                    break;
                }
            }
            $this->Repository->UpsertVideo(array(
                'external_id' => $Id,
                'title' => $Title !== '' ? $Title : $File,
                'thumbnail_url' => $ThumbnailUrl,
                'duration_seconds' => 0,
                'status' => 'available',
                'source_type' => 'local_file',
                'source_ref' => $Relative . '/' . $File,
                'content_url' => trailingslashit($BaseUrl) . rawurlencode($File),
                'post_url' => '',
                'raw' => array('relative_path' => $Relative . '/' . $File, 'extension' => $Ext),
            ));
            $Count++;
        }
        return $Count;
    }

    public function SyncPosts()
    {
        $CategoryId = intval(get_option('kornsw_ps_local_post_category', 0));
        if ($CategoryId <= 0) { return 0; }
        $Posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'cat' => $CategoryId,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        $Count = 0;
        foreach ($Posts as $Post) {
            $Thumb = get_the_post_thumbnail_url($Post->ID, 'medium_large');
            $this->Repository->UpsertVideo(array(
                'external_id' => 'post-' . intval($Post->ID),
                'title' => get_the_title($Post->ID),
                'thumbnail_url' => $Thumb ? $Thumb : '',
                'duration_seconds' => 0,
                'status' => 'available',
                'source_type' => 'post',
                'source_ref' => (string)$Post->ID,
                'content_url' => '',
                'post_url' => get_permalink($Post->ID),
                'raw' => array('post_id' => intval($Post->ID), 'excerpt' => wp_strip_all_tags(get_the_excerpt($Post->ID))),
            ));
            $Count++;
        }
        return $Count;
    }
}
