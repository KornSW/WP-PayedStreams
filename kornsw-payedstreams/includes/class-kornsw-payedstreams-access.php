<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Access
{
    private $Repository;
    public function __construct($Repository) { $this->Repository=$Repository; }

    public function CanView($VideoId, $UserId=null)
    {
        $Mappings=$this->Repository->GetMappingsForVideo($VideoId);
        foreach ($Mappings as $M) { if ($M['grant_type']==='public') { return array('allowed'=>true,'reason'=>'public'); } }
        if ($UserId===null) { $UserId=get_current_user_id(); }
        if (!$UserId) { return array('allowed'=>false,'reason'=>'login_or_purchase'); }
        $User=get_userdata($UserId); if (!$User) { return array('allowed'=>false,'reason'=>'user_missing'); }
        $ValidProducts=array_flip($this->Repository->GetValidProductIdsForUser($UserId));
        foreach ($Mappings as $M) {
            if ($M['grant_type']==='user' && (string)$M['grant_key']===(string)$UserId) { return array('allowed'=>true,'reason'=>'manual'); }
            if ($M['grant_type']==='role' && in_array($M['grant_key'],(array)$User->roles,true)) { return array('allowed'=>true,'reason'=>'role'); }
            if ($M['grant_type']==='product' && isset($ValidProducts[(string)$M['grant_key']])) { return array('allowed'=>true,'reason'=>'purchase','product_id'=>(string)$M['grant_key']); }
        }
        return array('allowed'=>false,'reason'=>'not_entitled');
    }
}
