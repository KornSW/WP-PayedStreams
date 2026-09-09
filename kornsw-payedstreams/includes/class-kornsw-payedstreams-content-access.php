<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Content_Access
{
    const MetaProducts = '_kornsw_ps_required_products';
    const MetaHideMenu = '_kornsw_ps_hide_menu_if_denied';

    private $Repository;

    public function __construct($Repository) { $this->Repository = $Repository; }

    public function Register()
    {
        add_action('add_meta_boxes', array($this, 'AddMetaBoxes'));
        add_action('save_post', array($this, 'SavePost'), 10, 2);
        add_filter('wp_nav_menu_objects', array($this, 'FilterMenuItems'), 20, 2);
        add_filter('the_content', array($this, 'FilterProtectedContent'), 1);
    }

    public function AddMetaBoxes()
    {
        add_meta_box('kornsw-ps-content-access', 'PayedStreams – Zugriff', array($this, 'RenderMetaBox'), array('post','page'), 'side', 'default');
    }

    public function RenderMetaBox($Post)
    {
        wp_nonce_field('kornsw_ps_content_access', 'kornsw_ps_content_access_nonce');
        $Selected = $this->GetRequiredProducts($Post->ID);
        $Hide = get_post_meta($Post->ID, self::MetaHideMenu, true) === '1';
        $Products = $this->Repository->GetProducts(true);
        echo '<p><strong>Nur verfügbar für Besitzer folgender Produkte</strong></p>';
        echo '<div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;background:#fff;padding:8px;">';
        if (empty($Products)) { echo '<em>Noch keine Digistore-Produkte synchronisiert.</em>'; }
        foreach ($Products as $Product) {
            $Id = (string)$Product['external_id'];
            echo '<label style="display:block;margin:0 0 6px"><input type="checkbox" name="kornsw_ps_required_products[]" value="'.esc_attr($Id).'" '.checked(in_array($Id,$Selected,true),true,false).'> '.esc_html($Product['name']).'</label>';
        }
        echo '</div>';
        echo '<p class="description">Keine Auswahl = Seite/Beitrag ist ohne PayedStreams-Produktprüfung verfügbar. Mehrere Produkte sind ODER-verknüpft.</p>';
        echo '<p><label><input type="checkbox" name="kornsw_ps_hide_menu_if_denied" value="1" '.checked($Hide,true,false).'> Menüpunkt ausblenden, wenn kein Zugriff besteht</label></p>';
    }

    public function SavePost($PostId, $Post)
    {
        if (!in_array($Post->post_type, array('post','page'), true)) { return; }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!isset($_POST['kornsw_ps_content_access_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['kornsw_ps_content_access_nonce'])), 'kornsw_ps_content_access')) { return; }
        if (!current_user_can('edit_post', $PostId)) { return; }
        $Products = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)(isset($_POST['kornsw_ps_required_products']) ? wp_unslash($_POST['kornsw_ps_required_products']) : array())))));
        update_post_meta($PostId, self::MetaProducts, $Products);
        update_post_meta($PostId, self::MetaHideMenu, !empty($_POST['kornsw_ps_hide_menu_if_denied']) ? '1' : '0');
    }

    private function GetRequiredProducts($PostId)
    {
        // WordPress returns an empty string when this post meta does not exist.
        // Never cast that value directly to array: (array)'' becomes array('')
        // and would incorrectly turn every unconfigured page into protected content.
        $Raw = get_post_meta($PostId, self::MetaProducts, true);
        if (!is_array($Raw)) { return array(); }

        $Result = array();
        foreach ($Raw as $ProductId) {
            $ProductId = trim((string)$ProductId);
            if ($ProductId !== '') { $Result[] = $ProductId; }
        }
        return array_values(array_unique($Result));
    }

    public function UserCanViewPost($PostId, $UserId = null)
    {
        $Required = $this->GetRequiredProducts($PostId);
        if (empty($Required)) { return true; }
        if ($UserId === null) { $UserId = get_current_user_id(); }
        if (!$UserId) { return false; }
        $Valid = array_flip($this->Repository->GetValidProductIdsForUser($UserId));
        foreach ($Required as $ProductId) { if (isset($Valid[(string)$ProductId])) { return true; } }
        return false;
    }

    public function FilterMenuItems($Items, $Args)
    {
        foreach ($Items as $Index => $Item) {
            if (!in_array($Item->object, array('post','page'), true)) { continue; }
            $Hide = get_post_meta(intval($Item->object_id), self::MetaHideMenu, true) === '1';
            if ($Hide && !$this->UserCanViewPost(intval($Item->object_id))) { unset($Items[$Index]); }
        }
        return $Items;
    }

    public function FilterProtectedContent($Content)
    {
        if (!is_singular(array('post','page')) || !in_the_loop() || !is_main_query()) { return $Content; }
        $PostId = get_the_ID();
        $Required = $this->GetRequiredProducts($PostId);
        if (empty($Required) || $this->UserCanViewPost($PostId)) { return $Content; }

        wp_enqueue_style('kornsw-payedstreams');
        $Products = array();
        foreach ($Required as $ProductId) {
            $Product = $this->Repository->GetProduct($ProductId);
            if ($Product && intval($Product['visible']) === 1) { $Products[] = $Product; }
        }

        ob_start();
        echo '<div class="kornsw-ps-protected-content">';
        if (!is_user_logged_in()) {
            $ReturnUrl = get_permalink($PostId);
            $MemberUrl = trim((string)get_option('kornsw_ps_member_registration_url', ''));
            if ($MemberUrl === '') { $MemberUrl = get_option('users_can_register') ? wp_registration_url() : wp_login_url($ReturnUrl); }
            echo '<p class="kornsw-ps-login-warning"><strong>Sie sind nicht eingeloggt und sehen daher keine gekauften Videos – <a href="'.esc_url($MemberUrl).'">hier anmelden</a>!</strong></p>';
            echo '<p><a class="button" href="'.esc_url(wp_login_url($ReturnUrl)).'">Schon gekauft? Einloggen</a></p>';
        }
        echo '<h3>Dieser Inhalt ist über folgende Angebote verfügbar</h3>';
        if (empty($Products)) {
            echo '<p>Für diesen Inhalt ist derzeit kein kaufbares Angebot hinterlegt.</p>';
        } else {
            foreach ($Products as $Product) {
                $Description = trim((string)$Product['description_override']) !== '' ? $Product['description_override'] : $Product['description'];
                $Checkout = (new KornSW_PayedStreams_Digistore_Client())->NormalizeCheckoutUrl($Product['checkout_url'], $Product['external_id']);
                echo '<div class="kornsw-ps-offer">';
                echo '<h4>'.esc_html($Product['name']).'</h4>';
                if ($Product['price_display'] !== '') { echo '<p class="kornsw-ps-price">'.esc_html($Product['price_display']).'</p>'; }
                if (trim(wp_strip_all_tags($Description)) !== '') { echo '<p>'.esc_html(wp_trim_words(wp_strip_all_tags($Description), 45)).'</p>'; }
                echo '<p><a class="button button-primary" href="'.esc_url($Checkout).'">Kaufen</a></p>';
                echo '</div>';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }
}
