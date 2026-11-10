<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
if (get_option('kornsw_ps_delete_data_on_uninstall','0') !== '1') { return; }
global $wpdb;
foreach (array('products','videos','mappings','buyer_links','entitlements','progress','anon_views','view_events','logs') as $Suffix) { $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'kornsw_ps_' . $Suffix); }
foreach (array('kornsw_ps_db_version','kornsw_ps_db_schema_revision','kornsw_ps_delete_data_on_uninstall','kornsw_ps_player_token_ttl','kornsw_ps_ipn_secret','kornsw_ps_digistore_api_key','kornsw_ps_bunny_api_key','kornsw_ps_bunny_library_id','kornsw_ps_bunny_cdn_hostname','kornsw_ps_bunny_token_key','kornsw_ps_mediathek_url') as $Option) { delete_option($Option); }
$Role=get_role('administrator'); if($Role){$Role->remove_cap('manage_kornsw_payedstreams');}
