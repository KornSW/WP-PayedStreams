<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Bunny_Client
{
    private $AccountApiKey;
    private $LibraryReadOnlyApiKey;
    private $LibraryId;
    private $TokenKey;
    private $CdnHostname;

    public function __construct()
    {
        $this->AccountApiKey = trim((string)get_option('kornsw_ps_bunny_api_key',''));
        $this->LibraryReadOnlyApiKey = trim((string)get_option('kornsw_ps_bunny_library_ro_key',''));
        $this->LibraryId = trim((string)get_option('kornsw_ps_bunny_library_id',''));
        $this->TokenKey = trim((string)get_option('kornsw_ps_bunny_token_key',''));
        $this->CdnHostname = trim((string)get_option('kornsw_ps_bunny_cdn_hostname',''));
    }

    private function Get($Path, $Query=array())
    {
        if ($this->LibraryReadOnlyApiKey==='' || $this->LibraryId==='') { throw new Exception('Bunny Mediathek-API-Key (RO) oder Mediathek-ID fehlt.'); }
        $Url='https://video.bunnycdn.com/library/' . rawurlencode($this->LibraryId) . $Path;
        if ($Query) { $Url=add_query_arg($Query,$Url); }
        $Response=wp_remote_get($Url,array('timeout'=>20,'headers'=>array('AccessKey'=>$this->LibraryReadOnlyApiKey,'Accept'=>'application/json')));
        if (is_wp_error($Response)) { throw new Exception($Response->get_error_message()); }
        $Code=wp_remote_retrieve_response_code($Response); $Body=wp_remote_retrieve_body($Response);
        if ($Code<200 || $Code>=300) { if ($Code===401) { throw new Exception('Bunny Stream HTTP 401: Der Bunny Mediathek-API-Key (RO) wurde für diese Mediathek-ID abgewiesen. Bitte unter Bunny → Stream → Mediathek → API den Read-only API-Key verwenden; der allgemeine Bunny API-Key funktioniert an video.bunnycdn.com nicht. Antwort: '.substr($Body,0,300)); } throw new Exception('Bunny Stream HTTP '.$Code.': '.substr($Body,0,500)); }
        $Data=json_decode($Body,true); if (!is_array($Data)) { throw new Exception('Bunny lieferte kein gültiges JSON.'); }
        return $Data;
    }

    private function TestAccountLibraryAccess()
    {
        if ($this->AccountApiKey==='') { throw new Exception('Bunny API-Key fehlt.'); }
        if ($this->LibraryId==='') { throw new Exception('Bunny Mediathek-ID fehlt.'); }
        $Url='https://api.bunny.net/videolibrary/' . rawurlencode($this->LibraryId);
        $Response=wp_remote_get($Url,array('timeout'=>20,'headers'=>array('AccessKey'=>$this->AccountApiKey,'Accept'=>'application/json')));
        if (is_wp_error($Response)) { throw new Exception('Bunny Account API: '.$Response->get_error_message()); }
        $Code=wp_remote_retrieve_response_code($Response);
        $Body=wp_remote_retrieve_body($Response);
        if ($Code<200 || $Code>=300) {
            if ($Code===401) { throw new Exception('Bunny Account API HTTP 401: Der allgemeine Bunny API-Key wurde abgewiesen. Bitte den Key aus Bunny → Account → API Key verwenden. Antwort: '.substr($Body,0,300)); }
            throw new Exception('Bunny Account API HTTP '.$Code.': '.substr($Body,0,500));
        }
        return true;
    }

    public function Test()
    {
        $this->TestAccountLibraryAccess();
        $this->Get('/videos',array('page'=>1,'itemsPerPage'=>1));
        return true;
    }

    public function ListVideos()
    {
        $Page=1; $All=array();
        do {
            $Data=$this->Get('/videos',array('page'=>$Page,'itemsPerPage'=>100));
            $Items=isset($Data['items'])&&is_array($Data['items'])?$Data['items']:(array_keys($Data)===range(0,count($Data)-1)?$Data:array());
            foreach ($Items as $Row) { if (is_array($Row)) { $All[]=$this->NormalizeVideo($Row); } }
            $Total=isset($Data['totalItems'])?intval($Data['totalItems']):count($All);
            $Page++;
        } while (!empty($Items) && count($All)<$Total && $Page<=100);
        return $All;
    }

    private function NormalizeVideo($Row)
    {
        $Id=isset($Row['guid'])?$Row['guid']:(isset($Row['videoId'])?$Row['videoId']:(isset($Row['id'])?$Row['id']:''));
        $Thumb='';
        if (!empty($Row['thumbnailUrl'])) { $Thumb=$Row['thumbnailUrl']; }
        elseif (!empty($Row['thumbnailFileName']) && $this->CdnHostname !== '' && $Id !== '') { $Thumb='https://' . preg_replace('/^https?:\/\//','',$this->CdnHostname) . '/' . rawurlencode($Id) . '/' . rawurlencode($Row['thumbnailFileName']); }
        return array('external_id'=>(string)$Id,'title'=>(string)(isset($Row['title'])?$Row['title']:$Id),'thumbnail_url'=>$Thumb,'duration_seconds'=>isset($Row['length'])?intval(round($Row['length'])):(isset($Row['duration'])?intval(round($Row['duration'])):0),'status'=>(string)(isset($Row['status'])?$Row['status']:'unknown'),'raw'=>$Row);
    }

    public function CreateSignedEmbedUrl($VideoId, $Autoplay=false)
    {
        if ($this->LibraryId==='') { throw new Exception('Bunny Library-ID fehlt.'); }
        $Url='https://iframe.mediadelivery.net/embed/' . rawurlencode($this->LibraryId) . '/' . rawurlencode($VideoId);
        $Query=array('autoplay'=>$Autoplay?'true':'false','preload'=>'true','responsive'=>'true');
        if ($this->TokenKey!=='') {
            $Ttl=max(300,intval(get_option('kornsw_ps_player_token_ttl','7200')));
            $Expires=time()+$Ttl;
            $Query['token']=hash('sha256',$this->TokenKey . $VideoId . $Expires);
            $Query['expires']=$Expires;
        }
        return add_query_arg($Query,$Url);
    }
}
