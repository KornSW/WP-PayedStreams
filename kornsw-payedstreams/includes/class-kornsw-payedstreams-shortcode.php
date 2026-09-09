<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Shortcode
{
    private $Repository;
    private $Access;

    public function __construct($Repository)
    {
        $this->Repository = $Repository;
        $this->Access = new KornSW_PayedStreams_Access($Repository);
    }

    public function Register()
    {
        add_shortcode('kornsw_payedstreams', array($this, 'Render'));
        add_shortcode('payedstreams', array($this, 'Render'));
        add_shortcode('mystreampurchases', array($this, 'RenderPurchases'));
        add_action('wp_enqueue_scripts', array($this, 'RegisterAssets'));
    }

    public function RegisterAssets()
    {
        wp_register_style('kornsw-payedstreams', KORNSW_PAYEDSTREAMS_URL . 'assets/css/frontend.css', array(), KORNSW_PAYEDSTREAMS_VERSION);
        wp_register_script('kornsw-playerjs', 'https://playerjs.io/js/player.js', array(), null, true);
        wp_register_script('kornsw-payedstreams', KORNSW_PAYEDSTREAMS_URL . 'assets/js/frontend.js', array('kornsw-playerjs'), KORNSW_PAYEDSTREAMS_VERSION, true);
    }

    private function EnsureFrontendAssets()
    {
        wp_enqueue_style('kornsw-payedstreams');
        wp_enqueue_script('kornsw-payedstreams');
        wp_localize_script('kornsw-payedstreams', 'KornSWPayedStreams', array(
            'rest' => esc_url_raw(rest_url('kornsw-payedstreams/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'loginUrl' => wp_login_url(),
            'memberUrl' => $this->GetMemberUrl(),
            'isLoggedIn' => is_user_logged_in() ? 1 : 0,
            'messages' => array(
                'unavailable' => 'Video vorübergehend nicht verfügbar.',
                'notAllowed' => 'Für diesen Inhalt besteht aktuell keine Freischaltung.',
            ),
        ));
    }

    private function GetMemberUrl()
    {
        $Url = trim((string)get_option('kornsw_ps_member_registration_url', ''));
        if ($Url !== '') { return $Url; }
        if (get_option('users_can_register')) { return wp_registration_url(); }
        return wp_login_url();
    }

    private function MaybeRefreshPurchases($UserId)
    {
        if (!$UserId) { return; }
        $LastSync = intval(get_user_meta($UserId, 'kornsw_ps_last_purchase_sync', true));
        if ($LastSync && time() - $LastSync <= 6 * HOUR_IN_SECONDS) { return; }
        $User = wp_get_current_user();
        try {
            (new KornSW_PayedStreams_Digistore_Client())->SyncUserPurchases($UserId, $User->user_email, $this->Repository);
            update_user_meta($UserId, 'kornsw_ps_last_purchase_sync', time());
        } catch (Exception $Ex) {
            KornSW_PayedStreams_Logger::Exception('Digistore24', $Ex, array('context'=>'frontend_cached_fallback','user_id'=>$UserId));
        }
    }

    public function Render($Attributes)
    {
        $this->EnsureFrontendAssets();
        $UserId = get_current_user_id();
        $this->MaybeRefreshPurchases($UserId);

        $Items = $this->Repository->GetVideos();
        $Cards = array();
        $RoleFilters = array();
        $ProductNames = array();
        $ProductCardCounts = array();
        $ValidProductIds = $UserId ? array_flip($this->Repository->GetValidProductIdsForUser($UserId)) : array();
        $CurrentUser = $UserId ? wp_get_current_user() : null;
        $CurrentRoles = $CurrentUser ? (array)$CurrentUser->roles : array();
        global $wp_roles;

        foreach ($Items as $Item) {
            $Mappings = $this->Repository->GetMappingsForVideo($Item['external_id']);
            if (empty($Mappings)) { continue; }

            $Decision = $this->Access->CanView($Item['external_id'], $UserId);
            $Products = $this->Repository->GetProductsForVideo($Item['external_id']);
            $IsPlayable = $Item['source_type'] !== 'post';
            $Progress = ($UserId && $IsPlayable) ? $this->Repository->GetProgress($UserId, $Item['external_id']) : null;
            $MappedProducts = array();
            $MappedRoles = array();
            $HasPurchasedProduct = false;

            foreach ($Mappings as $Mapping) {
                if ($Mapping['grant_type'] === 'product') {
                    $ProductId = (string)$Mapping['grant_key'];
                    $MappedProducts[] = $ProductId;
                    $ProductCardCounts[$ProductId] = isset($ProductCardCounts[$ProductId]) ? $ProductCardCounts[$ProductId] + 1 : 1;
                    if (isset($ValidProductIds[$ProductId])) { $HasPurchasedProduct = true; }
                }
                if ($Mapping['grant_type'] === 'role') {
                    $Role = (string)$Mapping['grant_key'];
                    $MappedRoles[] = $Role;
                    if (in_array($Role, $CurrentRoles, true)) {
                        $RoleName = isset($wp_roles->roles[$Role]['name']) ? translate_user_role($wp_roles->roles[$Role]['name']) : $Role;
                        $RoleFilters[$Role] = $RoleName;
                    }
                }
            }
            foreach ($Products as $Product) { $ProductNames[(string)$Product['external_id']] = $Product['name']; }

            // A role-only restriction is defined by the mappings themselves, not by whatever
            // product metadata happens to be returned for the content. This distinction is
            // important for anonymous visitors: role-only content must always show the member
            // login/registration dialog and must never fall through to the generic purchase
            // fallback.
            $HasMappedProducts = !empty($MappedProducts);
            $HasPurchaseOffer = !empty($Products);
            $IsRoleOnlyRestricted = !$Decision['allowed'] && !$HasPurchaseOffer && !empty($MappedRoles);
            if ($IsRoleOnlyRestricted && $UserId) {
                // Logged-in users who do not own the required role must not see role-only content at all.
                continue;
            }
            if (!$Decision['allowed'] && !$HasPurchaseOffer && !$IsRoleOnlyRestricted) {
                // No entitlement and no meaningful acquisition/member path: do not expose the card.
                continue;
            }

            $Cards[] = array(
                'video'=>$Item,
                'allowed'=>$Decision['allowed'],
                'reason'=>$Decision['reason'],
                'products'=>$Products,
                'progress'=>$Progress,
                'mapped_products'=>$MappedProducts,
                'mapped_roles'=>$MappedRoles,
                'playable'=>$IsPlayable,
                'purchased'=>$HasPurchasedProduct,
                'member_locked'=>$IsRoleOnlyRestricted,
                'has_purchase_offer'=>$HasPurchaseOffer,
            );
        }

        usort($Cards, function($A, $B) {
            $RankOf = function($Card) {
                if ($Card['video']['source_type'] === 'post') { return 5; }
                $Progress = $Card['progress'];
                if ($Progress && intval($Progress['completed']) === 0 && (intval($Progress['last_position_seconds']) > 0 || floatval($Progress['progress_percent']) > 0)) { return 0; }
                if ($Card['purchased'] || $Card['reason'] === 'purchase' || $Card['reason'] === 'manual') { return 1; }
                if ($Card['reason'] === 'role') { return 2; }
                if ($Card['reason'] === 'public') { return 3; }
                return 4;
            };
            $Ra = $RankOf($A);
            $Rb = $RankOf($B);
            return $Ra === $Rb ? strcasecmp($A['video']['title'], $B['video']['title']) : $Ra <=> $Rb;
        });

        $DeepVideo = isset($_GET['video']) ? sanitize_text_field(wp_unslash($_GET['video'])) : '';
        $AutoPlay = isset($_GET['autoplay']) && wp_unslash($_GET['autoplay']) === '1';
        $SelectedVideo = '';
        if ($DeepVideo !== '') {
            foreach ($Cards as $Card) {
                if ($Card['video']['external_id'] === $DeepVideo && $Card['allowed'] && $Card['playable']) { $SelectedVideo = $DeepVideo; break; }
            }
        }

        wp_localize_script('kornsw-payedstreams', 'KornSWPayedStreamsPage', array(
            'deepVideo'=>$DeepVideo,
            'defaultVideo'=>$SelectedVideo,
            'autoplay'=>$AutoPlay ? 1 : 0,
        ));

        ob_start();
        echo '<div class="kornsw-ps-library ' . ($SelectedVideo !== '' ? 'has-player' : 'no-player') . '">';
        echo '<div class="kornsw-ps-layout">';
        echo '<div class="kornsw-ps-player-column"' . ($SelectedVideo === '' ? ' hidden' : '') . '><div class="kornsw-ps-player-shell"><div class="kornsw-ps-player-placeholder"><strong>Video wird geladen …</strong></div></div></div>';
        echo '<div class="kornsw-ps-media-column">';
        echo '<div class="kornsw-ps-toolbar"><label>Ansicht <select class="kornsw-ps-filter">';
        echo '<option value="all">Alle Inhalte</option>';
        echo '<option value="mine">Meine Inhalte</option>';
        echo '<option value="purchased">Gekaufte Inhalte</option>';
        echo '<option value="free">Kostenlose Inhalte</option>';
        echo '<option value="continue">Weiterschauen</option>';
        echo '<option value="buyable">Kaufbare Inhalte</option>';
        echo '<option value="external">Außerhalb der Mediathek</option>';
        foreach ($RoleFilters as $Slug=>$Name) { echo '<option value="role:' . esc_attr($Slug) . '">' . esc_html($Name) . '</option>'; }
        foreach ($ProductCardCounts as $ProductId=>$Count) {
            if ($Count <= 1 || !isset($ProductNames[$ProductId])) { continue; }
            echo '<option value="product:' . esc_attr($ProductId) . '">' . esc_html($ProductNames[$ProductId] . ' (Bundle)') . '</option>';
        }
        echo '</select></label>';
        if (is_user_logged_in()) { echo '<button type="button" class="kornsw-ps-purchases-open" title="Meine Käufe" aria-label="Meine Käufe">🛍️</button>'; } else { echo '<span class="kornsw-ps-login-warning-inline">Sie sind nicht eingeloggt und sehen daher keine gekauften Videos – <a href="' . esc_url($this->GetMemberUrl()) . '">hier anmelden</a>!</span>'; }
        echo '</div>';
        echo '<div class="kornsw-ps-grid">';
        foreach ($Cards as $Card) { $this->RenderCard($Card); }
        echo '</div></div></div>';
        if (is_user_logged_in()) {
            echo '<template class="kornsw-ps-purchases-template">' . $this->GetPurchasesMarkup($UserId, true) . '</template>';
        }
        echo '</div>';
        echo '<div class="kornsw-ps-modal" hidden><div class="kornsw-ps-modal-backdrop"></div><div class="kornsw-ps-modal-dialog" role="dialog" aria-modal="true"><div class="kornsw-ps-modal-content"></div></div></div>';
        return ob_get_clean();
    }

    public function RenderPurchases($Attributes)
    {
        $this->EnsureFrontendAssets();
        if (!is_user_logged_in()) {
            $Return = get_permalink();
            return '<div class="kornsw-ps-purchases"><p>Bitte einloggen, um Ihre gekauften Inhalte anzuzeigen.</p><p><a class="button" href="' . esc_url(wp_login_url($Return)) . '">Einloggen</a></p></div>';
        }
        $UserId = get_current_user_id();
        $this->MaybeRefreshPurchases($UserId);
        return $this->GetPurchasesMarkup($UserId, false);
    }

    private function GetPurchasesMarkup($UserId, $Compact)
    {
        $Rows = $this->Repository->GetEntitlementsForUser($UserId);
        ob_start();
        echo '<div class="kornsw-ps-purchases' . ($Compact ? ' is-compact' : '') . '">';
        echo '<div class="kornsw-ps-purchases-head"><h3>Meine Käufe</h3><button type="button" class="button kornsw-ps-refresh-purchases">Aktualisieren</button></div>';
        if (empty($Rows)) {
            echo '<p>Für diesen Account sind derzeit keine Käufe hinterlegt.</p>';
        } else {
            echo '<div class="kornsw-ps-purchase-list">';
            foreach ($Rows as $Row) {
                $IsCurrentlyValid = intval($Row['is_valid']) === 1 && (empty($Row['valid_until']) || strtotime($Row['valid_until'] . ' UTC') >= time());
                $Name = trim((string)$Row['product_name']) !== '' ? $Row['product_name'] : ('Produkt #' . $Row['product_external_id']);
                echo '<div class="kornsw-ps-purchase-row ' . ($IsCurrentlyValid ? 'is-valid' : 'is-invalid') . '">';
                echo '<div><strong>' . esc_html($Name) . '</strong><span>Produkt #' . esc_html($Row['product_external_id']) . '</span></div>';
                echo '<div class="kornsw-ps-purchase-status"><strong>' . ($IsCurrentlyValid ? '✓ gültig' : 'nicht gültig') . '</strong>';
                if (!empty($Row['valid_until'])) { echo '<span>bis ' . esc_html(wp_date('d.m.Y', strtotime($Row['valid_until'] . ' UTC'))) . '</span>'; }
                if (!empty($Row['source_status'])) { echo '<span>Status: ' . esc_html($Row['source_status']) . '</span>'; }
                echo '</div></div>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private function RenderCard($Card)
    {
        $V = $Card['video'];
        $Allowed = $Card['allowed'];
        $Progress = $Card['progress'];
        $Continue = $Progress && intval($Progress['completed']) === 0 && (!empty($Progress['last_viewed_at']) || intval($Progress['last_position_seconds']) > 0 || floatval($Progress['progress_percent']) > 0);
        $Classes = array('kornsw-ps-card', $Allowed ? 'is-accessible' : 'is-buyable');
        if ($Card['reason'] === 'public') { $Classes[] = 'is-free'; }
        if ($Card['purchased']) { $Classes[] = 'is-purchased'; }
        if ($Continue) { $Classes[] = 'has-progress'; }
        if ($V['source_type'] === 'post') { $Classes[] = 'is-post'; }
        if (!empty($Card['member_locked'])) { $Classes[] = 'is-member-locked-card'; }
        $Attrs = array(
            'data-video'=>$V['external_id'],
            'data-allowed'=>$Allowed ? '1' : '0',
            'data-reason'=>$Card['reason'],
            'data-products'=>implode(',', $Card['mapped_products']),
            'data-roles'=>implode(',', $Card['mapped_roles']),
            'data-progress'=>$Progress ? floatval($Progress['progress_percent']) : 0,
            'data-source'=>$V['source_type'],
            'data-target'=>$V['post_url'],
            'data-member-locked'=>!empty($Card['member_locked']) ? '1' : '0',
            'data-access-mode'=>!empty($Card['member_locked']) ? 'member-login' : ($Allowed ? 'play' : (!empty($Card['has_purchase_offer']) ? 'purchase' : 'none')),
        );
        $Title = $this->StripMediaExtension($V['title']);

        echo '<article class="' . esc_attr(implode(' ', $Classes)) . '"';
        foreach ($Attrs as $Key=>$Value) { echo ' ' . esc_attr($Key) . '="' . esc_attr($Value) . '"'; }
        echo '><button type="button" class="kornsw-ps-card-action"><div class="kornsw-ps-thumb">';
        if ($V['thumbnail_url']) { echo '<img loading="lazy" src="' . esc_url($V['thumbnail_url']) . '" alt="">'; }
        else { echo '<div class="kornsw-ps-thumb-fallback">' . ($V['source_type'] === 'post' ? '↗' : '▶') . '</div>'; }
        if ($V['source_type'] === 'post') { echo '<span class="kornsw-ps-external-overlay">↗ Außerhalb der Mediathek</span>'; }
        if (!empty($Card['member_locked'])) { echo '<span class="kornsw-ps-badge is-member-locked" title="Nur für Mitglieder" aria-label="Nur für Mitglieder"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2Zm-7-2a2 2 0 0 1 4 0v2h-4V6Zm7 13H7v-9h10v9Z"/></svg></span>'; }
        elseif (!$Allowed && !empty($Card['has_purchase_offer'])) { echo '<span class="kornsw-ps-badge is-buy" title="Kaufbar">🛒</span>'; }
        elseif ($Card['purchased']) { echo '<span class="kornsw-ps-badge is-purchased" title="Gekauft">🛒<i>✓</i></span>'; }
        elseif ($Card['reason'] === 'role') { echo '<span class="kornsw-ps-badge is-community" title="Community-Inhalt">👥</span>'; }
        // Public/free videos intentionally have no status icon. External posts retain their text overlay above.
        if ($Progress && floatval($Progress['progress_percent']) > 0) { echo '<span class="kornsw-ps-progress"><i style="width:' . esc_attr(min(100, floatval($Progress['progress_percent']))) . '%"></i></span>'; }
        echo '</div><div class="kornsw-ps-card-body"><strong>' . esc_html($Title) . '</strong>';
        if ($V['duration_seconds']) { echo '<span>' . esc_html($this->FormatDuration($V['duration_seconds'])) . '</span>'; }
        elseif ($V['source_type'] === 'post') { echo '<span>Außerhalb der Mediathek öffnen ↗</span>'; }
        if (!$Allowed) { $Price = $this->LowestPrice($Card['products']); if ($Price !== '') { echo '<span class="kornsw-ps-card-price">ab ' . esc_html($Price) . '</span>'; } }
        echo '</div></button><script type="application/json" class="kornsw-ps-products-json">' . wp_json_encode(array_map(array($this, 'PublicProduct'), $Card['products']), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) . '</script></article>';
    }

    private function LowestPrice($Products)
    {
        foreach ($Products as $Product) { if (trim((string)$Product['price_display']) !== '') { return trim((string)$Product['price_display']); } }
        return '';
    }

    public function PublicProduct($Product)
    {
        $Description = trim((string)$Product['description_override']) !== '' ? $Product['description_override'] : $Product['description'];
        $Checkout = (new KornSW_PayedStreams_Digistore_Client())->NormalizeCheckoutUrl($Product['checkout_url'], $Product['external_id']);
        return array(
            'id'=>$Product['external_id'],
            'name'=>$Product['name'],
            'description'=>wp_strip_all_tags($Description),
            'price'=>$Product['price_display'],
            'checkout_url'=>$Checkout,
            'featured'=>intval($Product['featured']) === 1,
        );
    }

    private function StripMediaExtension($Text)
    {
        return preg_replace('/\.(mp4|m4v|mov|webm|avi|mkv|wmv|mpeg|mpg)\s*$/i', '', trim((string)$Text));
    }

    private function FormatDuration($Seconds)
    {
        $Seconds = intval($Seconds);
        return sprintf('%d:%02d', floor($Seconds / 60), $Seconds % 60);
    }
}
