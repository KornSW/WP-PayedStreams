<?php
if (!defined('ABSPATH')) { exit; }
class KornSW_PayedStreams_Plugin
{
    public function Run()
    {
        if (get_option('kornsw_ps_db_version') !== KORNSW_PAYEDSTREAMS_VERSION || KornSW_PayedStreams_Activator::IsSchemaOutdated()) { KornSW_PayedStreams_Activator::EnsureSchema(); }
        $AdministratorRole = get_role('administrator');
        if ($AdministratorRole !== null && !$AdministratorRole->has_cap('manage_kornsw_payedstreams')) { $AdministratorRole->add_cap('manage_kornsw_payedstreams'); }
        $Repository=new KornSW_PayedStreams_Repository();
        (new KornSW_PayedStreams_Admin($Repository))->Register();
        (new KornSW_PayedStreams_Shortcode($Repository))->Register();
        (new KornSW_PayedStreams_Content_Access($Repository))->Register();
        (new KornSW_PayedStreams_Rest($Repository))->Register();
        add_action('init', array($this, 'HandleDigistoreIpn'), 0);
        add_action('template_redirect', array($this, 'HandlePurchaseReturn'));
    }

    public function HandleDigistoreIpn()
    {
        if (!isset($_GET['kornsw_ps_digistore_ipn']) || wp_unslash($_GET['kornsw_ps_digistore_ipn']) !== '1') { return; }

        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');

        $Method = strtoupper((string)$_SERVER['REQUEST_METHOD']);
        if ($Method !== 'POST' && $Method !== 'GET') {
            status_header(405);
            header('Allow: POST, GET');
            echo 'METHOD NOT ALLOWED';
            exit;
        }

        // IMPORTANT: Verify the signature against the original request values first.
        // Sanitizing or renaming fields before SHA calculation changes the signed payload.
        $RawPayload = $Method === 'POST' ? wp_unslash($_POST) : wp_unslash($_GET);
        if ($Method === 'GET') {
            unset($RawPayload['kornsw_ps_digistore_ipn']);
        }

        $Client = new KornSW_PayedStreams_Digistore_Client();
        if (!$Client->VerifyIpnSignature($RawPayload)) {
            $Debug = $Client->GetLastIpnVerificationInfo();
            KornSW_PayedStreams_Logger::Log('WARNING', 'Digistore24-IPN', 'IPN mit ungültiger Signatur verworfen.', array(
                'endpoint'=>'direct',
                'method'=>$Method,
                'received_prefix'=>isset($Debug['received']) ? substr($Debug['received'],0,16) : '',
                'expected_prefix'=>isset($Debug['expected']) ? substr($Debug['expected'],0,16) : '',
                'field_count'=>isset($Debug['field_count']) ? intval($Debug['field_count']) : 0
            ));
            status_header(403);
            echo 'INVALID SIGNATURE';
            exit;
        }

        $Payload = array();
        foreach ($RawPayload as $Key => $Value) {
            $SafeKey = sanitize_key($Key);
            $Payload[$SafeKey] = is_array($Value) ? array_map('sanitize_text_field', $Value) : sanitize_text_field((string)$Value);
        }

        $Repository = new KornSW_PayedStreams_Repository();
        $Email = isset($Payload['buyer_email']) ? sanitize_email($Payload['buyer_email']) : '';
        $ProductId = isset($Payload['product_id']) ? sanitize_text_field($Payload['product_id']) : '';
        $MatchedUser = false;

        if ($Email !== '' && $ProductId !== '') {
            $User = get_user_by('email', $Email);
            if ($User) {
                $Validity = $Client->DetermineValidity($Payload);
                $Repository->UpsertEntitlement($User->ID, $ProductId, $Validity['valid'], $Validity['status'], $Validity['valid_until'], $Payload);
                if (!empty($Payload['buyer_id'])) { $Repository->LinkBuyer($User->ID, sanitize_text_field($Payload['buyer_id']), $Email); }
                if ($Validity['valid']) {
                    $ReturnVideoId = $Repository->GetFirstPlayableVideoIdForProduct($ProductId);
                    if ($ReturnVideoId !== '') {
                        update_user_meta($User->ID, 'kornsw_ps_pending_return_video', $ReturnVideoId);
                        update_user_meta($User->ID, 'kornsw_ps_pending_return_product', $ProductId);
                        update_user_meta($User->ID, 'kornsw_ps_pending_return_at', time());
                    }
                }
                $MatchedUser = true;
            }
        }

        KornSW_PayedStreams_Logger::Log('INFO', 'Digistore24-IPN', 'Gültige IPN verarbeitet.', array(
            'endpoint'=>'direct',
            'event'=>isset($Payload['event']) ? $Payload['event'] : '',
            'product_id'=>$ProductId,
            'matched_user'=>$MatchedUser,
            'api_mode'=>isset($Payload['api_mode']) ? $Payload['api_mode'] : ''
        ));

        status_header(200);
        echo 'OK';
        exit;
    }

    public function HandlePurchaseReturn()
    {
        if (!isset($_GET['kornsw_ps_return']) || wp_unslash($_GET['kornsw_ps_return']) !== '1') { return; }
        $Target = trim((string)get_option('kornsw_ps_mediathek_url',''));
        if ($Target === '') { $Target = home_url('/'); }
        $Custom = isset($_GET['custom']) ? sanitize_text_field(wp_unslash($_GET['custom'])) : '';
        $VideoId = '';
        $AutoPlay = '0';
        if (preg_match('/^kps_([A-Za-z0-9_]+)_a([01])$/', $Custom, $Match)) {
            $VideoId = str_replace('_','-', $Match[1]);
            $AutoPlay = $Match[2];
        }

        if ($VideoId === '') {
            $ProductId = '';
            foreach (array('product_id', 'productId', 'product') as $Key) {
                if (isset($_GET[$Key]) && trim((string)wp_unslash($_GET[$Key])) !== '') {
                    $ProductId = sanitize_text_field(wp_unslash($_GET[$Key]));
                    break;
                }
            }
            if ($ProductId !== '') {
                $Repository = new KornSW_PayedStreams_Repository();
                $VideoId = $Repository->GetFirstPlayableVideoIdForProduct($ProductId);
            }
        }

        if ($VideoId === '' && is_user_logged_in()) {
            $UserId = get_current_user_id();
            $PendingAt = intval(get_user_meta($UserId, 'kornsw_ps_pending_return_at', true));
            if ($PendingAt > 0 && (time() - $PendingAt) <= 30 * MINUTE_IN_SECONDS) {
                $VideoId = sanitize_text_field((string)get_user_meta($UserId, 'kornsw_ps_pending_return_video', true));
            }
            delete_user_meta($UserId, 'kornsw_ps_pending_return_video');
            delete_user_meta($UserId, 'kornsw_ps_pending_return_product');
            delete_user_meta($UserId, 'kornsw_ps_pending_return_at');
        }

        if ($VideoId !== '') {
            $Target = add_query_arg(array('video'=>$VideoId,'autoplay'=>$AutoPlay), $Target);
        }
        wp_safe_redirect($Target);
        exit;
    }
}
