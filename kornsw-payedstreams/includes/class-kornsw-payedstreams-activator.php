<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Activator
{
    /**
     * Revision of the table layout. Raise it whenever EnsureSchema() changes, so the upgrade
     * also runs on installations whose plugin version string did not change (e.g. dev deployments).
     * 2 = videos.published_at / videos.first_seen_at (date sorting of the media library).
     */
    const SCHEMA_REVISION = 2;

    public static function IsSchemaOutdated()
    {
        return intval(get_option('kornsw_ps_db_schema_revision', 0)) < self::SCHEMA_REVISION;
    }

    public static function Activate()
    {
        self::EnsureSchema();
        $Role = get_role('administrator');
        if ($Role !== null) {
            $Role->add_cap('manage_kornsw_payedstreams');
        }
        add_option('kornsw_ps_delete_data_on_uninstall', '0');
        add_option('kornsw_ps_player_token_ttl', '7200');
        add_option('kornsw_ps_ipn_secret', wp_generate_password(48, false, false));
        add_option('kornsw_ps_local_wp_media_enabled', '0');
        add_option('kornsw_ps_local_video_folder', 'uploads/payedstreams');
        add_option('kornsw_ps_local_post_category', '0');
    }

    public static function EnsureSchema()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $C = $wpdb->get_charset_collate();
        $P = $wpdb->prefix . 'kornsw_ps_';

        dbDelta("CREATE TABLE {$P}products (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            external_id varchar(191) NOT NULL,
            name varchar(255) NOT NULL,
            description longtext NULL,
            description_override longtext NULL,
            price_display varchar(120) NULL,
            price_amount decimal(12,2) NULL,
            currency varchar(8) NULL,
            checkout_url text NULL,
            status varchar(80) NULL,
            visible tinyint(1) NOT NULL DEFAULT 1,
            sort_order int(11) NOT NULL DEFAULT 100,
            featured tinyint(1) NOT NULL DEFAULT 0,
            raw_json longtext NULL,
            synced_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_external_id (external_id),
            KEY idx_visible (visible)
        ) {$C};");

        dbDelta("CREATE TABLE {$P}videos (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            external_id varchar(191) NOT NULL,
            title varchar(255) NOT NULL,
            thumbnail_url text NULL,
            duration_seconds int(11) unsigned NULL,
            status varchar(80) NULL,
            source_type varchar(40) NOT NULL DEFAULT 'bunny',
            source_ref varchar(255) NULL,
            content_url text NULL,
            post_url text NULL,
            raw_json longtext NULL,
            published_at datetime NULL,
            first_seen_at datetime NULL,
            synced_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_external_id (external_id)
        ) {$C};");

        // Rows that existed before first_seen_at was introduced: the last sync time is the best
        // available approximation of when the content showed up (UTC, like all stored times).
        $wpdb->query("UPDATE {$P}videos SET first_seen_at = COALESCE(synced_at, UTC_TIMESTAMP()) WHERE first_seen_at IS NULL");

        dbDelta("CREATE TABLE {$P}mappings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            video_external_id varchar(191) NOT NULL,
            grant_type varchar(40) NOT NULL,
            grant_key varchar(191) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mapping (video_external_id, grant_type, grant_key),
            KEY idx_video (video_external_id),
            KEY idx_grant (grant_type, grant_key)
        ) {$C};");

        dbDelta("CREATE TABLE {$P}buyer_links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            buyer_external_id varchar(191) NULL,
            buyer_email varchar(255) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_buyer (user_id, buyer_external_id),
            KEY idx_user_id (user_id),
            KEY idx_buyer_external_id (buyer_external_id),
            KEY idx_buyer_email (buyer_email)
        ) {$C};");

        dbDelta("CREATE TABLE {$P}entitlements (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            product_external_id varchar(191) NOT NULL,
            source_status varchar(100) NULL,
            is_valid tinyint(1) NOT NULL DEFAULT 0,
            valid_until datetime NULL,
            source_updated_at datetime NULL,
            checked_at datetime NULL,
            raw_json longtext NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_product (user_id, product_external_id),
            KEY idx_user_valid (user_id, is_valid)
        ) {$C};");

        dbDelta("CREATE TABLE {$P}progress (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            video_external_id varchar(191) NOT NULL,
            first_started_at datetime NULL,
            last_viewed_at datetime NULL,
            last_position_seconds int(11) unsigned NOT NULL DEFAULT 0,
            max_position_seconds int(11) unsigned NOT NULL DEFAULT 0,
            progress_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            completed tinyint(1) NOT NULL DEFAULT 0,
            qualified_views int(11) unsigned NOT NULL DEFAULT 0,
            last_view_counted_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_video (user_id, video_external_id),
            KEY idx_video_lastviewed (video_external_id, last_viewed_at)
        ) {$C};");

        dbDelta("CREATE TABLE {$P}anon_views (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            video_external_id varchar(191) NOT NULL,
            view_day date NOT NULL,
            session_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_anon_daily (video_external_id, view_day, session_hash),
            KEY idx_video_day (video_external_id, view_day)
        ) {$C};");


        dbDelta("CREATE TABLE {$P}view_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            video_external_id varchar(191) NOT NULL,
            viewer_type varchar(20) NOT NULL,
            viewer_hash char(64) NOT NULL,
            view_bucket varchar(32) NOT NULL,
            occurred_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_view_bucket (video_external_id, viewer_hash, view_bucket),
            KEY idx_video_time (video_external_id, occurred_at)
        ) {$C};");

        dbDelta("CREATE TABLE {$P}logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            component varchar(80) NOT NULL,
            message text NOT NULL,
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_level (level)
        ) {$C};");

        update_option('kornsw_ps_db_version', KORNSW_PAYEDSTREAMS_VERSION, false);
        update_option('kornsw_ps_db_schema_revision', self::SCHEMA_REVISION, false);
    }
}
