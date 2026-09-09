<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Admin
{
    const Capability = 'manage_kornsw_payedstreams';
    private $Repository;

    public function __construct($Repository) { $this->Repository = $Repository; }

    public function Register()
    {
        add_action('admin_menu', array($this, 'AddAdminMenu'));
        add_action('admin_init', array($this, 'RegisterSettings'));
        add_action('admin_enqueue_scripts', array($this, 'Assets'));
        $Actions = array('test_digistore','test_bunny','sync_digistore','sync_bunny','sync_local','clear_logs','save_mapping_content','save_mapping_grant','save_product');
        foreach ($Actions as $Action) { add_action('admin_post_kornsw_ps_' . $Action, array($this, 'Handle_' . $Action)); }
    }

    public function AddAdminMenu()
    {
        add_submenu_page('upload.php', 'KornSW PayedStreams', 'PayedStreams (KornSW)', self::Capability, 'kornsw-payedstreams', array($this, 'Render'));
    }

    public function RegisterSettings()
    {
        register_setting('kornsw_ps_settings','kornsw_ps_digistore_api_key',array('sanitize_callback'=>'sanitize_text_field'));
        register_setting('kornsw_ps_settings','kornsw_ps_bunny_api_key',array('sanitize_callback'=>'sanitize_text_field'));
        register_setting('kornsw_ps_settings','kornsw_ps_bunny_library_ro_key',array('sanitize_callback'=>'sanitize_text_field'));
        register_setting('kornsw_ps_settings','kornsw_ps_bunny_library_id',array('sanitize_callback'=>'sanitize_text_field'));
        register_setting('kornsw_ps_settings','kornsw_ps_bunny_cdn_hostname',array('sanitize_callback'=>'sanitize_text_field'));
        register_setting('kornsw_ps_settings','kornsw_ps_mediathek_url',array('sanitize_callback'=>'esc_url_raw'));
        register_setting('kornsw_ps_settings','kornsw_ps_member_registration_url',array('sanitize_callback'=>'esc_url_raw'));
        register_setting('kornsw_ps_settings','kornsw_ps_bunny_token_key',array('sanitize_callback'=>'sanitize_text_field'));
        register_setting('kornsw_ps_settings','kornsw_ps_ipn_secret',array('sanitize_callback'=>'sanitize_text_field'));
        register_setting('kornsw_ps_settings','kornsw_ps_player_token_ttl',array('sanitize_callback'=>'absint'));
        register_setting('kornsw_ps_settings','kornsw_ps_delete_data_on_uninstall',array('sanitize_callback'=>array($this,'Bool')));
        register_setting('kornsw_ps_local_settings','kornsw_ps_local_wp_media_enabled',array('sanitize_callback'=>array($this,'Bool')));
        register_setting('kornsw_ps_local_settings','kornsw_ps_local_video_folder',array('sanitize_callback'=>array($this,'SanitizeFolder')));
        register_setting('kornsw_ps_local_settings','kornsw_ps_local_post_category',array('sanitize_callback'=>'absint'));
    }

    public function Bool($Value) { return $Value === '1' ? '1' : '0'; }
    public function SanitizeFolder($Value) { return trim(str_replace('\\','/',sanitize_text_field($Value)),'/'); }
    public function Assets($Hook) { if ($Hook === 'media_page_kornsw-payedstreams') { wp_enqueue_style('kornsw-ps-admin', KORNSW_PAYEDSTREAMS_URL . 'assets/css/admin.css', array(), KORNSW_PAYEDSTREAMS_VERSION); } }

    public function Render()
    {
        if (!current_user_can(self::Capability)) { wp_die('Keine Berechtigung.'); }
        $Tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connections';
        echo '<div class="wrap kornsw-ps-admin"><h1>KornSW PayedStreams</h1><p>Version ' . esc_html(KORNSW_PAYEDSTREAMS_VERSION) . ' – WordPress führt Benutzer und Mediathek, Digistore24 Käufe, Bunny und lokale Quellen die Inhalte.</p>';
        $this->Notices();
        $Tabs = array('connections'=>'Verbindungen','mapping-content'=>'Mapping nach Inhalt','mapping-product'=>'Mapping nach Produkt/Rolle','videos'=>'Videos & Statistik','local-content'=>'Lokale Inhalte','products'=>'Produkte','diagnostics'=>'Diagnose');
        echo '<nav class="nav-tab-wrapper">';
        foreach ($Tabs as $Key=>$Label) { echo '<a class="nav-tab ' . ($Tab === $Key ? 'nav-tab-active' : '') . '" href="' . esc_url(add_query_arg(array('page'=>'kornsw-payedstreams','tab'=>$Key),admin_url('upload.php'))) . '">' . esc_html($Label) . '</a>'; }
        echo '</nav>';
        switch ($Tab) {
            case 'mapping-content': $this->MappingContent(); break;
            case 'mapping-product': $this->MappingGrant(); break;
            case 'videos': $this->Videos(); break;
            case 'local-content': $this->LocalContent(); break;
            case 'products': $this->Products(); break;
            case 'diagnostics': $this->Diagnostics(); break;
            default: $this->Connections(); break;
        }
        echo '</div>';
    }

    private function Connections()
    {
        echo '<div class="kornsw-ps-grid2"><section class="kornsw-ps-box"><h2>API-Verbindungen</h2><form method="post" action="options.php">';
        settings_fields('kornsw_ps_settings'); echo '<table class="form-table">';
        $this->Field('Digistore24 API-Key','kornsw_ps_digistore_api_key',true,'API-Key für lesende Digistore24-Abfragen.');
        $this->Field('Bunny API-Key','kornsw_ps_bunny_api_key',true,'Allgemeiner Bunny-Account-API-Key.');
        $this->Field('Bunny Mediathek-ID','kornsw_ps_bunny_library_id',false,'Numerische ID der Bunny Video Library.');
        $this->Field('Bunny Mediathek-API-Key (RO)','kornsw_ps_bunny_library_ro_key',true,'Read-only API-Key der konkreten Video Library; wird als HTTP-Header AccessKey verwendet.');
        $this->Field('Bunny CDN Hostname','kornsw_ps_bunny_cdn_hostname',false,'Pull-Zone-Hostname, z. B. vz-xxx.b-cdn.net.');
        $this->Field('Mediathek-Seiten-URL','kornsw_ps_mediathek_url',false,'URL der WordPress-Seite mit [kornsw_payedstreams]. Keine Bunny-URL.');
        $this->Field('Anmelde-/Registrierungs-URL für Mitglieder','kornsw_ps_member_registration_url',false,'Ziel für den Link „hier anmelden“ bei nicht eingeloggten Besuchern und rollenbasierten Mitglieder-Inhalten. Beispiel: Ihre Community-Login-/Registrierungsseite.');
        $this->Field('Bunny Wiedergabe-Token-Key','kornsw_ps_bunny_token_key',true,'Token Authentication Key für geschützte Wiedergabe.');
        $this->Field('Digistore24 IPN Passwort','kornsw_ps_ipn_secret',true,'Gemeinsames Secret der generischen Digistore24-IPN-Verbindung.');
        $this->Field('Player-Token Gültigkeit (Sek.)','kornsw_ps_player_token_ttl',false);
        echo '<tr><th>Deinstallation</th><td><label><input type="checkbox" name="kornsw_ps_delete_data_on_uninstall" value="1" ' . checked('1',get_option('kornsw_ps_delete_data_on_uninstall','0'),false) . '> Daten bei Deinstallation löschen</label><p class="description">Standard: Daten bleiben erhalten.</p></td></tr></table>';
        submit_button('Einstellungen speichern'); echo '</form></section>';

        echo '<section class="kornsw-ps-box"><h2>Test & Synchronisation</h2>';
        $this->Button('test_digistore','Digistore-Verbindung testen'); $this->Button('sync_digistore','Digistore-Produkte synchronisieren');
        $this->Button('test_bunny','Bunny-Verbindung testen'); $this->Button('sync_bunny','Bunny-Videos synchronisieren');
        $IpnUrl = home_url('/?kornsw_ps_digistore_ipn=1');
        echo '<hr><h3>Digistore24-IPN einrichten</h3><p><strong>IPN-URL</strong></p>'; $this->CopyField($IpnUrl,'kornsw-ps-ipn-url');
        echo '<p class="description">Die feste <code>1</code> ist nur der Schalter für unseren Empfänger. Hier gehören keine Digistore-Platzhalter hinein.</p>';
        echo '<ol><li>Digistore24 → Integrationen/IPN → neue generische Anbindung.</li><li>HTTP-Methode: <strong>POST</strong>.</li><li>Upsells gruppieren: <strong>Nein</strong>.</li><li>IPN-URL: exakt die oben angezeigte URL.</li><li>IPN-Passwort: exakt derselbe Wert wie links.</li><li>Relevante Ereignisse: Zahlung, Rückerstattung, Chargeback, verpasste Folgezahlung und Ende des bezahlten Zeitraums; Abo-Kündigung/Wiederaufnahme zusätzlich sinnvoll.</li><li>Timing: vor Weiterleitung auf die Dankeseite.</li><li>Test muss mit HTTP 200 und <code>OK</code> beantwortet werden.</li></ol>';
        echo '<h3>Kauf-Rückleitung</h3>'; $this->CopyField(home_url('/?kornsw_ps_return=1'),'kornsw-ps-return-url');
        echo '<h3>Shortcode</h3><code>[kornsw_payedstreams]</code></section></div>';
    }

    private function MappingContent()
    {
        $Videos=$this->Repository->GetVideos(); $Products=$this->Repository->GetProducts(); global $wp_roles; $Roles=$wp_roles->roles;
        $Selected=isset($_GET['video'])?sanitize_text_field(wp_unslash($_GET['video'])):(!empty($Videos)?$Videos[0]['external_id']:'');
        $Existing=$Selected?$this->Repository->GetMappingsForVideo($Selected):array(); $Set=array(); foreach($Existing as$M){$Set[$M['grant_type'].':'.$M['grant_key']]=true;}
        echo '<section class="kornsw-ps-box"><h2>Mapping nach Inhalt</h2>';
        if(empty($Videos)){echo'<p>Keine Inhalte vorhanden.</p></section>';return;}
        echo '<div class="kornsw-ps-mapping-layout"><nav class="kornsw-ps-mapping-nav">';
        foreach($Videos as$V){$Url=add_query_arg(array('page'=>'kornsw-payedstreams','tab'=>'mapping-content','video'=>$V['external_id']),admin_url('upload.php'));echo'<a class="'.($Selected===$V['external_id']?'is-active':'').'" href="'.esc_url($Url).'"><span>'.esc_html($this->SourceLabel($V)).'</span><strong>'.esc_html($V['title']).'</strong></a>';}
        echo '</nav><div class="kornsw-ps-mapping-main">';
        $SelectedVideo=$this->Repository->GetVideo($Selected); if($SelectedVideo){echo'<h3>'.esc_html($SelectedVideo['title']).'</h3><p class="description">'.esc_html($this->SourceLabel($SelectedVideo)).' · <code>'.esc_html($Selected).'</code></p>';}
        echo'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('kornsw_ps_save_mapping_content','_wpnonce',true,false).'<input type="hidden" name="action" value="kornsw_ps_save_mapping_content"><input type="hidden" name="video" value="'.esc_attr($Selected).'">';
        echo'<div class="kornsw-ps-mapping-section"><h4>Sonderzugriff</h4><label class="kornsw-ps-list-row"><input type="checkbox" name="public" value="1" '.checked(isset($Set['public:*']),true,false).'><span><strong>Öffentlich verfügbar</strong><small>Kein Login erforderlich.</small></span></label></div>';
        echo'<div class="kornsw-ps-mapping-section"><h4>Digistore24-Produkte</h4><div class="kornsw-ps-scroll-list">';foreach($Products as$P){echo'<label class="kornsw-ps-list-row"><input type="checkbox" name="products[]" value="'.esc_attr($P['external_id']).'" '.checked(isset($Set['product:'.$P['external_id']]),true,false).'><span><strong>'.esc_html($P['name']).'</strong><small>Produkt #'.esc_html($P['external_id']).'</small></span></label>';}echo'</div></div>';
        echo'<div class="kornsw-ps-mapping-section"><h4>WordPress-Rollen</h4><div class="kornsw-ps-scroll-list">';foreach($Roles as$Slug=>$R){echo'<label class="kornsw-ps-list-row"><input type="checkbox" name="roles[]" value="'.esc_attr($Slug).'" '.checked(isset($Set['role:'.$Slug]),true,false).'><span><strong>'.esc_html(translate_user_role($R['name'])).'</strong><small>'.esc_html($Slug).'</small></span></label>';}echo'</div></div>';
        $UserMappings=array_filter($Existing,function($M){return $M['grant_type']==='user';}); echo'<div class="kornsw-ps-mapping-section"><h4>Manuelle Benutzerfreigaben</h4>';
        foreach($UserMappings as$M){$U=get_user_by('id',intval($M['grant_key']));if($U){echo'<label class="kornsw-ps-list-row"><input type="checkbox" checked name="users[]" value="'.esc_attr($U->ID).'"><span><strong>'.esc_html($U->user_email).'</strong><small>Dauerhafte manuelle Freigabe</small></span></label>';}}
        echo'<p><input type="email" name="add_user_email" placeholder="Benutzer-E-Mail" class="regular-text"> <span class="description">Neue dauerhafte Freigabe hinzufügen.</span></p></div>'; submit_button('Zuordnung speichern'); echo'</form></div></div></section>';
    }

    private function MappingGrant()
    {
        $Products=$this->Repository->GetProducts(); $Videos=$this->Repository->GetVideos(); global$wp_roles; $Grants=array();
        foreach($Products as$P){$Grants['product:'.$P['external_id']]='Produkt: '.$P['name'];} foreach($wp_roles->roles as$Slug=>$R){$Grants['role:'.$Slug]='Rolle: '.translate_user_role($R['name']);} $Grants['public:*']='Öffentlich verfügbar';
        $Selected=isset($_GET['grant'])?sanitize_text_field(wp_unslash($_GET['grant'])):(!empty($Grants)?array_key_first($Grants):'');
        $Parts=$Selected?explode(':',$Selected,2):array('',''); $Rows=$Selected?$this->Repository->GetMappingsByGrant($Parts[0],$Parts[1]):array(); $Set=array();foreach($Rows as$R){$Set[$R['video_external_id']]=true;}
        echo'<section class="kornsw-ps-box"><h2>Mapping nach Produkt/Rolle</h2>'; if(!$Selected){echo'<p>Keine Zugangswege vorhanden.</p></section>';return;}
        echo'<div class="kornsw-ps-mapping-layout"><nav class="kornsw-ps-mapping-nav">';foreach($Grants as$K=>$L){$Url=add_query_arg(array('page'=>'kornsw-payedstreams','tab'=>'mapping-product','grant'=>$K),admin_url('upload.php'));echo'<a class="'.($Selected===$K?'is-active':'').'" href="'.esc_url($Url).'"><strong>'.esc_html($L).'</strong></a>';}echo'</nav><div class="kornsw-ps-mapping-main"><h3>'.esc_html(isset($Grants[$Selected])?$Grants[$Selected]:$Selected).'</h3>';
        echo'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('kornsw_ps_save_mapping_grant','_wpnonce',true,false).'<input type="hidden" name="action" value="kornsw_ps_save_mapping_grant"><input type="hidden" name="grant" value="'.esc_attr($Selected).'"><div class="kornsw-ps-scroll-list kornsw-ps-content-list">';foreach($Videos as$V){echo'<label class="kornsw-ps-list-row"><input type="checkbox" name="videos[]" value="'.esc_attr($V['external_id']).'" '.checked(isset($Set[$V['external_id']]),true,false).'><span><strong>'.esc_html($V['title']).'</strong><small>'.esc_html($this->SourceLabel($V)).' · '.esc_html($V['external_id']).'</small></span></label>';}echo'</div>';submit_button('Zuordnung speichern');echo'</form></div></div></section>';
    }

    private function Videos()
    {
        $Videos=$this->Repository->GetVideos(); echo'<section class="kornsw-ps-box"><h2>Inhalte & anonyme Statistik</h2><div style="display:flex;gap:8px;flex-wrap:wrap">'; $this->Button('sync_bunny','Bunny-Videos synchronisieren'); $this->Button('sync_local','Lokale Inhalte synchronisieren'); echo'</div><table class="widefat striped"><thead><tr><th>Inhalt</th><th>Quelle</th><th>ID</th><th>Dauer</th><th>7 Tage</th><th>30 Tage</th><th>Ø Fortschritt</th></tr></thead><tbody>';
        foreach($Videos as$V){$S7=$this->Repository->GetVideoStats($V['external_id'],7);$S30=$this->Repository->GetVideoStats($V['external_id'],30);$Metric=$V['source_type']==='post'?'Klicks':'Views';$People=$V['source_type']==='post'?'Besucher':'Zuschauer';echo'<tr><td>'.esc_html($V['title']).'</td><td>'.esc_html($this->SourceLabel($V)).'</td><td><code>'.esc_html($V['external_id']).'</code></td><td>'.esc_html($V['source_type']==='post'?'–':$this->Dur($V['duration_seconds'])).'</td><td>'.esc_html($S7['views']).' '.esc_html($Metric).' / '.esc_html($S7['unique_users']).' '.esc_html($People).'</td><td>'.esc_html($S30['views']).' '.esc_html($Metric).' / '.esc_html($S30['unique_users']).' '.esc_html($People).'</td><td>'.esc_html($V['source_type']==='post'?'–':$S30['avg_progress'].'%').'</td></tr>'; }
        echo'</tbody></table><p class="description">Statistiken werden aggregiert dargestellt; keine Benutzernamen.</p></section>';
    }

    private function LocalContent()
    {
        $Categories=get_categories(array('hide_empty'=>false)); $Folder=get_option('kornsw_ps_local_video_folder','uploads/payedstreams');
        echo'<section class="kornsw-ps-box"><h2>Lokale Inhalte</h2><p>Lokale Videos und Beiträge werden nach der Synchronisation wie Bunny-Inhalte in beiden Mapping-Ansichten angeboten.</p><form method="post" action="options.php">'; settings_fields('kornsw_ps_local_settings');
        echo'<table class="form-table"><tr><th>WordPress-Medienvideos</th><td><label><input type="checkbox" name="kornsw_ps_local_wp_media_enabled" value="1" '.checked('1',get_option('kornsw_ps_local_wp_media_enabled','0'),false).'> Videos aus der WordPress-Mediathek als auswählbare Inhalte einlesen</label></td></tr>';
        echo'<tr><th><label for="kornsw_ps_local_video_folder">Video-Ordner</label></th><td><input class="regular-text code" id="kornsw_ps_local_video_folder" name="kornsw_ps_local_video_folder" value="'.esc_attr($Folder).'"><p class="description">Relativ zu <code>wp-content</code>. Default: <code>uploads/payedstreams</code>. Effektiver Pfad: <code>'.esc_html(wp_normalize_path(WP_CONTENT_DIR.'/'.$Folder)).'</code>. Der Ordner muss per Web erreichbar sein.</p></td></tr>';
        echo'<tr><th><label for="kornsw_ps_local_post_category">Beitragskategorie</label></th><td><select name="kornsw_ps_local_post_category" id="kornsw_ps_local_post_category"><option value="0">– keine Beiträge einreihen –</option>'; $Selected=intval(get_option('kornsw_ps_local_post_category',0)); foreach($Categories as$C){echo'<option value="'.esc_attr($C->term_id).'" '.selected($Selected,$C->term_id,false).'>'.esc_html($C->name).'</option>';} echo'</select><p class="description">Alle veröffentlichten Beiträge dieser Kategorie werden als Mediathek-Kacheln aufgenommen. Sie öffnen den Beitrag statt des Players.</p></td></tr></table>';
        submit_button('Lokale Einstellungen speichern'); echo'</form>'; $this->Button('sync_local','Lokale Inhalte jetzt synchronisieren'); echo'</section>';
    }

    private function Products()
    {
        $Products=$this->Repository->GetProducts(); echo'<section class="kornsw-ps-box"><h2>Digistore24-Produkte</h2>'; $this->Button('sync_digistore','Digistore-Produkte synchronisieren'); echo'<p>Preis und Stammdaten werden synchronisiert; Beschreibung kann lokal überschrieben werden. Kann Digistore keinen aktuellen Preis liefern, bleibt der zuletzt bekannte Preis erhalten.</p>';
        foreach($Products as$P){echo'<form class="kornsw-ps-product-edit" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('kornsw_ps_save_product','_wpnonce',true,false).'<input type="hidden" name="action" value="kornsw_ps_save_product"><input type="hidden" name="product" value="'.esc_attr($P['external_id']).'"><h3>'.esc_html($P['name']).' <small>#'.esc_html($P['external_id']).'</small></h3><p><strong>Preis:</strong> '.esc_html($P['price_display']!==''?$P['price_display']:'noch nicht aus API ermittelbar').'</p><p><strong>Digistore-Beschreibung:</strong> '.esc_html(wp_trim_words(wp_strip_all_tags($P['description']),40)).'</p><label>Beschreibung in Mediathek überschreiben<br><textarea name="description_override" rows="3" class="large-text">'.esc_textarea($P['description_override']).'</textarea></label><p><label><input type="checkbox" name="visible" value="1" '.checked(intval($P['visible']),1,false).'> Für Mediathek kaufbar</label> &nbsp; <label><input type="checkbox" name="featured" value="1" '.checked(intval($P['featured']),1,false).'> Im Kaufdialog hervorheben</label> &nbsp; Reihenfolge <input type="number" name="sort_order" value="'.esc_attr($P['sort_order']).'" style="width:80px"></p><button class="button">Speichern</button></form>';}
        echo'</section>';
    }

    private function Diagnostics()
    {
        global$wpdb;$T=$wpdb->prefix.'kornsw_ps_logs';$Rows=$wpdb->get_results("SELECT * FROM {$T} ORDER BY id DESC LIMIT 300",ARRAY_A);echo'<section class="kornsw-ps-box"><h2>Technische Diagnose</h2>';$this->Button('clear_logs','Logs leeren');echo'<table class="widefat striped"><thead><tr><th>UTC</th><th>Level</th><th>Komponente</th><th>Meldung</th></tr></thead><tbody>';foreach($Rows as$R){echo'<tr><td>'.esc_html($R['created_at']).'</td><td>'.esc_html($R['level']).'</td><td>'.esc_html($R['component']).'</td><td>'.esc_html($R['message']).'</td></tr>';}echo'</tbody></table></section>';
    }

    private function SourceLabel($V)
    {
        $Type=isset($V['source_type'])?$V['source_type']:'bunny'; $Map=array('bunny'=>'Bunny','wp_media'=>'WP-Medien','local_file'=>'Serverdatei','post'=>'Beitrag'); return isset($Map[$Type])?$Map[$Type]:$Type;
    }
    private function CopyField($Value,$Id){echo'<div style="display:flex;gap:6px;align-items:center;max-width:100%;"><input id="'.esc_attr($Id).'" type="text" readonly class="large-text code" value="'.esc_attr($Value).'"><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\''.esc_js($Id).'\').value);this.textContent=\'Kopiert!\';">Kopieren</button></div>';}
    private function Field($Label,$Name,$Secret,$Description=''){echo'<tr><th><label for="'.esc_attr($Name).'">'.esc_html($Label).'</label></th><td><input class="regular-text" '.($Secret?'type="password" autocomplete="off"':'type="text"').' id="'.esc_attr($Name).'" name="'.esc_attr($Name).'" value="'.esc_attr(get_option($Name,'')).'">';if($Description!==''){echo'<p class="description">'.wp_kses_post($Description).'</p>';}echo'</td></tr>';}
    private function Button($Action,$Label){echo'<p style="display:inline-block;margin-right:8px"><a class="button button-secondary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=kornsw_ps_'.$Action),'kornsw_ps_'.$Action)).'">'.esc_html($Label).'</a></p>';}
    private function RequireAction($Action){if(!current_user_can(self::Capability)){wp_die('Keine Berechtigung.');}check_admin_referer('kornsw_ps_'.$Action);}
    private function Redirect($Tab,$Notice){wp_safe_redirect(add_query_arg(array('page'=>'kornsw-payedstreams','tab'=>$Tab,'notice'=>$Notice),admin_url('upload.php')));exit;}

    public function Handle_test_digistore(){ $this->RequireAction('test_digistore');try{(new KornSW_PayedStreams_Digistore_Client())->Test();KornSW_PayedStreams_Logger::Log('INFO','Digistore24','Verbindungstest erfolgreich.');$this->Redirect('connections','digistore_ok');}catch(Exception$E){KornSW_PayedStreams_Logger::Exception('Digistore24',$E);$this->Redirect('connections','digistore_error');}}
    public function Handle_test_bunny(){ $this->RequireAction('test_bunny');try{(new KornSW_PayedStreams_Bunny_Client())->Test();KornSW_PayedStreams_Logger::Log('INFO','Bunny','Verbindungstest erfolgreich.');$this->Redirect('connections','bunny_ok');}catch(Exception$E){KornSW_PayedStreams_Logger::Exception('Bunny',$E);$this->Redirect('connections','bunny_error');}}
    public function Handle_sync_digistore(){ $this->RequireAction('sync_digistore');try{$Rows=(new KornSW_PayedStreams_Digistore_Client())->ListProducts();foreach($Rows as$R){$this->Repository->UpsertProduct($R);}KornSW_PayedStreams_Logger::Log('INFO','Digistore24','Produkte synchronisiert.',array('count'=>count($Rows)));$this->Redirect('products','sync_ds_ok');}catch(Exception$E){KornSW_PayedStreams_Logger::Exception('Digistore24',$E);$this->Redirect('connections','digistore_error');}}
    public function Handle_sync_bunny(){ $this->RequireAction('sync_bunny');try{$Rows=(new KornSW_PayedStreams_Bunny_Client())->ListVideos();foreach($Rows as$R){$R['source_type']='bunny';$R['source_ref']=$R['external_id'];$R['content_url']='';$R['post_url']='';$this->Repository->UpsertVideo($R);}KornSW_PayedStreams_Logger::Log('INFO','Bunny','Videos synchronisiert.',array('count'=>count($Rows)));$this->Redirect('videos','sync_bunny_ok');}catch(Exception$E){KornSW_PayedStreams_Logger::Exception('Bunny',$E);$this->Redirect('connections','bunny_error');}}
    public function Handle_sync_local(){ $this->RequireAction('sync_local');try{$Count=(new KornSW_PayedStreams_Local_Content($this->Repository))->Sync();KornSW_PayedStreams_Logger::Log('INFO','Lokale Inhalte','Lokale Inhalte synchronisiert.',array('count'=>$Count));$this->Redirect('local-content','sync_local_ok');}catch(Exception$E){KornSW_PayedStreams_Logger::Exception('Lokale Inhalte',$E);$this->Redirect('local-content','local_error');}}
    public function Handle_clear_logs(){ $this->RequireAction('clear_logs');global$wpdb;$wpdb->query('TRUNCATE TABLE '.$wpdb->prefix.'kornsw_ps_logs');$this->Redirect('diagnostics','logs_cleared');}
    public function Handle_save_mapping_content(){ $this->RequireAction('save_mapping_content');$Video=sanitize_text_field(wp_unslash($_POST['video']));$M=array();if(!empty($_POST['public'])){$M[]=array('grant_type'=>'public','grant_key'=>'*');}foreach((array)(isset($_POST['products'])?$_POST['products']:array())as$X){$M[]=array('grant_type'=>'product','grant_key'=>sanitize_text_field(wp_unslash($X)));}foreach((array)(isset($_POST['roles'])?$_POST['roles']:array())as$X){$M[]=array('grant_type'=>'role','grant_key'=>sanitize_key(wp_unslash($X)));}foreach((array)(isset($_POST['users'])?$_POST['users']:array())as$X){$M[]=array('grant_type'=>'user','grant_key'=>absint($X));}$Email=isset($_POST['add_user_email'])?sanitize_email(wp_unslash($_POST['add_user_email'])):'';if($Email){$U=get_user_by('email',$Email);if($U){$M[]=array('grant_type'=>'user','grant_key'=>$U->ID);}}$this->Repository->ReplaceMappingsForVideo($Video,$M);$this->Redirect('mapping-content','mapping_saved');}
    public function Handle_save_mapping_grant(){ $this->RequireAction('save_mapping_grant');$Grant=sanitize_text_field(wp_unslash($_POST['grant']));list($Type,$Key)=explode(':',$Grant,2);$Videos=array_map('sanitize_text_field',(array)(isset($_POST['videos'])?wp_unslash($_POST['videos']):array()));$this->Repository->ReplaceMappingsForGrant($Type,$Key,$Videos);$this->Redirect('mapping-product','mapping_saved');}
    public function Handle_save_product(){ $this->RequireAction('save_product');$this->Repository->UpdateProductPresentation(sanitize_text_field(wp_unslash($_POST['product'])),sanitize_textarea_field(wp_unslash($_POST['description_override'])),!empty($_POST['visible']),!empty($_POST['featured']),intval($_POST['sort_order']));$this->Redirect('products','product_saved');}
    private function Notices(){if(empty($_GET['notice']))return;$N=sanitize_key(wp_unslash($_GET['notice']));$Map=array('digistore_ok'=>array('success','Digistore24-Verbindung erfolgreich.'),'bunny_ok'=>array('success','Bunny-Verbindung erfolgreich.'),'sync_ds_ok'=>array('success','Digistore24-Produkte synchronisiert.'),'sync_bunny_ok'=>array('success','Bunny-Videos synchronisiert.'),'sync_local_ok'=>array('success','Lokale Inhalte synchronisiert.'),'mapping_saved'=>array('success','Mapping gespeichert.'),'product_saved'=>array('success','Produkteinstellungen gespeichert.'),'logs_cleared'=>array('success','Logs geleert.'),'digistore_error'=>array('error','Digistore24-Aufruf fehlgeschlagen. Details unter Diagnose.'),'bunny_error'=>array('error','Bunny-Aufruf fehlgeschlagen. Details unter Diagnose.'),'local_error'=>array('error','Synchronisation lokaler Inhalte fehlgeschlagen. Details unter Diagnose.'));if(isset($Map[$N]))echo'<div class="notice notice-'.$Map[$N][0].' is-dismissible"><p>'.esc_html($Map[$N][1]).'</p></div>';}
    private function Dur($S){$S=intval($S);return sprintf('%d:%02d',floor($S/60),$S%60);}
}
