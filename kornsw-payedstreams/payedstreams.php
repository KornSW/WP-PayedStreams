<?php
/**
 * Plugin Name: KornSW PayedStreams
 * Update URI: https://raw.githubusercontent.com/KornSW/WP-PayedStreams/master/doc/kornsw-payedstreams.update.json
 * Plugin URI: https://github.com/KornSW/WP-PayedStreams
 * Description: Verbindet WordPress, Digistore24 und Bunny Stream zu einer zentralen Mediathek mit Kauf-, Rollen- und Benutzerberechtigungen.
 * Version: 1.0.7
 * Author: KornSW
 * Text Domain: kornsw-payedstreams
 */

if (!defined('ABSPATH')) {
    exit;
}


/*************** SELF-UPDATE ***************/
define( 'KSWKORNSWPAYEDSFB02_SELF_UPDATE_DIAGNOSTICS', false );
require_once __DIR__ . '/self-update.php';
kswkornswpayedsfb02_bootstrap( __FILE__ );
/*******************************************/

define('KORNSW_PAYEDSTREAMS_VERSION', '1.0.6');
define('KORNSW_PAYEDSTREAMS_FILE', __FILE__);
define('KORNSW_PAYEDSTREAMS_PATH', plugin_dir_path(__FILE__));
define('KORNSW_PAYEDSTREAMS_URL', plugin_dir_url(__FILE__));

require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-logger.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-activator.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-repository.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-digistore-client.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-bunny-client.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-local-content.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-access.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-content-access.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-rest.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-admin.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-shortcode.php';
require_once KORNSW_PAYEDSTREAMS_PATH . 'includes/class-kornsw-payedstreams-plugin.php';

register_activation_hook(__FILE__, array('KornSW_PayedStreams_Activator', 'Activate'));

$KornSW_PayedStreams_Plugin = new KornSW_PayedStreams_Plugin();
$KornSW_PayedStreams_Plugin->Run();
