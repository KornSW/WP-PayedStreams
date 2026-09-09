<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Logger
{
    public static function Log($Level, $Component, $Message, $Context = null)
    {
        global $wpdb;
        $Table = $wpdb->prefix . 'kornsw_ps_logs';
        $wpdb->insert($Table, array(
            'level' => substr((string)$Level, 0, 20),
            'component' => substr((string)$Component, 0, 80),
            'message' => (string)$Message,
            'context_json' => $Context === null ? null : wp_json_encode($Context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    public static function Exception($Component, $Exception, $Context = null)
    {
        $Payload = is_array($Context) ? $Context : array();
        $Payload['exception'] = get_class($Exception);
        $Payload['file'] = $Exception->getFile();
        $Payload['line'] = $Exception->getLine();
        self::Log('ERROR', $Component, $Exception->getMessage(), $Payload);
    }
}
