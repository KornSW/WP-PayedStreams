<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Digistore_Client
{
    private $ApiKey;
    private $LastIpnVerificationInfo = array();

    public function __construct()
    {
        $this->ApiKey = trim((string)get_option('kornsw_ps_digistore_api_key', ''));
    }

    private function Call($Function, $Parameters = array(), $Method = 'GET')
    {
        if ($this->ApiKey === '') { throw new Exception('Digistore24 API-Key fehlt.'); }
        $Url = 'https://www.digistore24.com/api/call/' . rawurlencode($Function);
        $Args = array('timeout'=>20,'headers'=>array('X-DS-API-KEY'=>$this->ApiKey,'Accept'=>'application/json'));
        if (strtoupper($Method) === 'POST') { $Args['body']=$Parameters; $Response=wp_remote_post($Url,$Args); }
        else { if (!empty($Parameters)) { $Url=add_query_arg($Parameters,$Url); } $Response=wp_remote_get($Url,$Args); }
        if (is_wp_error($Response)) { throw new Exception($Response->get_error_message()); }
        $Code = wp_remote_retrieve_response_code($Response);
        $Body = wp_remote_retrieve_body($Response);
        $Data = json_decode($Body, true);
        if ($Code < 200 || $Code >= 300) { throw new Exception('Digistore24 HTTP ' . $Code . ': ' . substr($Body,0,500)); }
        if (!is_array($Data)) { throw new Exception('Digistore24 lieferte kein gültiges JSON.'); }
        if (isset($Data['result']) && $Data['result'] === 'error') { throw new Exception(isset($Data['message']) ? $Data['message'] : 'Digistore24 API-Fehler.'); }
        return isset($Data['data']) ? $Data['data'] : $Data;
    }

    public function Test()
    {
        $Data = $this->Call('listProducts');
        return is_array($Data);
    }

    public function ListProducts()
    {
        $Data = $this->Call('listProducts');
        $Rows = $this->ExtractList($Data, array('products','items','product_list'));
        $Result = array();
        foreach ($Rows as $Row) {
            if (!is_array($Row)) { continue; }
            $Normalized = $this->NormalizeProductRow($Row);
            if ($Normalized !== null) { $Result[] = $Normalized; }
        }
        return $Result;
    }

    public function GetProduct($ProductId)
    {
        try { $Data=$this->Call('getProduct',array('product_id'=>$ProductId)); }
        catch (Exception $Ex) { return null; }
        $Row = is_array($Data) && isset($Data['product']) && is_array($Data['product']) ? $Data['product'] : $Data;
        if (!is_array($Row)) { return null; }
        return $this->NormalizeProductRow($Row);
    }

    private function NormalizeProductRow($Row)
    {
        $Id = $this->First($Row, array('id','product_id','productId'));
        if ($Id === null || $Id === '') { return null; }
        $Price = $this->ExtractPriceData($Row);
        $Checkout = $this->First($Row,array('orderform_url','order_form_url','buy_url','checkout_url'));
        if (!$Checkout) { $Checkout = 'https://www.checkout-ds24.com/product/' . rawurlencode((string)$Id) . '/'; }
        $Checkout = $this->NormalizeCheckoutUrl((string)$Checkout, (string)$Id);
        return array(
            'external_id'=>(string)$Id,
            'name'=>(string)($this->First($Row,array('name','product_name','title')) ?: ('Produkt ' . $Id)),
            'description'=>(string)($this->FirstRecursive($Row,array('description','short_description','description_short','product_description')) ?: ''),
            'price_display'=>$Price['display'],
            'price_amount'=>$Price['amount'],
            'currency'=>$Price['currency'],
            'checkout_url'=>(string)$Checkout,
            'status'=>(string)($this->First($Row,array('status','active','is_active')) ?: 'unknown'),
            'raw'=>$Row,
        );
    }

    public function NormalizeCheckoutUrl($Url, $ProductId)
    {
        $Url = trim((string)$Url);
        $ProductId = trim((string)$ProductId);
        if ($Url === '') { return 'https://www.checkout-ds24.com/product/' . rawurlencode($ProductId) . '/'; }
        $Parts = wp_parse_url($Url);
        $Host = isset($Parts['host']) ? strtolower((string)$Parts['host']) : '';
        if ($Host === 'digistore24.com' || $Host === 'www.digistore24.com') {
            $Query = isset($Parts['query']) && $Parts['query'] !== '' ? '?' . $Parts['query'] : '';
            return 'https://www.checkout-ds24.com/product/' . rawurlencode($ProductId) . '/' . $Query;
        }
        return $Url;
    }

    private function ExtractPriceData($Row)
    {
        $Display = $this->FirstRecursive($Row, array('price_display','price_formatted','display_price','formatted_price'));
        $Amount = $this->FirstRecursive($Row, array('first_amount','product_amount','amount_brutto','price_amount','price','amount'));
        if (is_array($Amount)) { $Amount = $this->FirstRecursive($Amount,array('first_amount','amount','value','price')); }
        $Currency = $this->FirstRecursive($Row,array('currency','price_currency','currency_code'));
        if (is_string($Amount)) {
            $Candidate = str_replace(array('.', ','), array('', '.'), preg_replace('/[^0-9,\.\-]/', '', $Amount));
            if (substr_count($Amount, '.') === 1 && substr_count($Amount, ',') === 0) { $Candidate = preg_replace('/[^0-9\.\-]/', '', $Amount); }
            if (is_numeric($Candidate)) { $Amount = (float)$Candidate; }
        }
        if (!is_numeric($Amount)) { $Amount = null; }
        if (($Display === null || $Display === '') && $Amount !== null) {
            $Display = number_format((float)$Amount,2,',','.') . ($Currency ? ' ' . strtoupper((string)$Currency) : '');
            $BillingType = strtolower((string)$this->FirstRecursive($Row,array('billing_type','payment_type')));
            $Interval = strtolower((string)$this->FirstRecursive($Row,array('billing_interval','interval','billing_frequency')));
            if ($BillingType === 'subscription') {
                if (strpos($Interval,'month') !== false) { $Display .= ' / Monat'; }
                elseif (strpos($Interval,'year') !== false) { $Display .= ' / Jahr'; }
                else { $Display .= ' / Abo'; }
            }
        }
        return array('display'=>(string)$Display,'amount'=>$Amount,'currency'=>(string)$Currency);
    }

    private function FirstRecursive($Value, $Keys, $Depth = 0)
    {
        if (!is_array($Value) || $Depth > 7) { return null; }
        foreach ($Keys as $Key) {
            if (array_key_exists($Key,$Value) && $Value[$Key] !== null && $Value[$Key] !== '') { return $Value[$Key]; }
        }
        foreach ($Value as $Child) {
            if (!is_array($Child)) { continue; }
            $Found = $this->FirstRecursive($Child,$Keys,$Depth+1);
            if ($Found !== null && $Found !== '') { return $Found; }
        }
        return null;
    }

    public function ListPurchasesOfEmail($Email)
    {
        $Data = $this->Call('listPurchasesOfEmail', array('email'=>$Email,'limit'=>100));
        return $this->ExtractList($Data, array('purchases','items','orders','purchase_list'));
    }

    public function SyncUserPurchases($UserId, $Email, $Repository)
    {
        $Purchases = $this->ListPurchasesOfEmail($Email);
        $SeenProducts = array();
        foreach ($Purchases as $Purchase) {
            if (!is_array($Purchase)) { continue; }
            $ProductId = $this->First($Purchase,array('product_id','productId','product')); if (is_array($ProductId)) { $ProductId=$this->First($ProductId,array('id','product_id')); }
            if ($ProductId === null || $ProductId === '') { continue; }
            $BuyerId = $this->First($Purchase,array('buyer_id','buyerId','customer_id'));
            $BuyerEmail = $this->First($Purchase,array('buyer_email','email','buyer[email]')) ?: $Email;
            $Repository->LinkBuyer($UserId,$BuyerId,$BuyerEmail);
            $Validity = $this->DetermineValidity($Purchase);
            $Repository->UpsertEntitlement($UserId,(string)$ProductId,$Validity['valid'],$Validity['status'],$Validity['valid_until'],$Purchase);
            $SeenProducts[(string)$ProductId] = true;
        }
        KornSW_PayedStreams_Logger::Log('INFO','Digistore24','Käufe eines WordPress-Benutzers synchronisiert.',array('user_id'=>$UserId,'email_hash'=>hash('sha256',strtolower(trim($Email))),'purchase_count'=>count($Purchases),'product_count'=>count($SeenProducts)));
        return array_keys($SeenProducts);
    }

    public function DetermineValidity($Purchase)
    {
        $Event = strtolower(trim((string)($this->First($Purchase,array('event')) ?: '')));
        $Status = strtolower(trim((string)($this->First($Purchase,array('billing_status','order_billing_status','status','purchase_status','order_status','subscription_status','transaction_type')) ?: 'unknown')));
        $ValidUntilRaw = $this->First($Purchase,array('access_until','valid_until','paid_until','last_paid_day','next_payment_at','rebilling_next_payment_at'));
        $ValidUntil = $this->NormalizeDate($ValidUntilRaw);

        if ($Event === 'on_payment') { return array('valid'=>true,'status'=>$Event,'valid_until'=>$ValidUntil); }
        if ($Event === 'on_payment_missed' || $Event === 'payment_denial' || $Event === 'on_chargeback' || $Event === 'on_refund' || $Event === 'last_paid_day') {
            return array('valid'=>false,'status'=>$Event,'valid_until'=>$ValidUntil);
        }

        // A cancellation of future rebilling must not revoke an already paid period.
        if ($Event === 'on_rebill_cancelled' || $Event === 'on_rebill_resumed') {
            $OrderIsPaid = $this->First($Purchase,array('order_is_paid'));
            if ($OrderIsPaid !== null) { return array('valid'=>$this->ToBool($OrderIsPaid),'status'=>$Event,'valid_until'=>$ValidUntil); }
            if ($ValidUntil !== null && strtotime($ValidUntil . ' UTC') >= time()) { return array('valid'=>true,'status'=>$Event,'valid_until'=>$ValidUntil); }
            if ($Status === 'paying' || $Status === 'completed') { return array('valid'=>true,'status'=>$Event,'valid_until'=>$ValidUntil); }
        }

        $Negative = array('refunded','refund','chargeback','charged_back','failed','unpaid','payment_missing','rejected','revoked','inactive','deleted');
        $Positive = array('paid','completed','complete','active','ok','success','successful','paying','payment_received');
        $IsValid = null;
        foreach ($Negative as $Token) { if ($Status === $Token || strpos($Status,$Token)!==false) { $IsValid=false; break; } }
        if ($IsValid === null) { foreach ($Positive as $Token) { if ($Status === $Token || strpos($Status,$Token)!==false) { $IsValid=true; break; } } }

        foreach (array('is_refunded','is_chargeback','is_revoked') as $Key) {
            if (isset($Purchase[$Key]) && $this->ToBool($Purchase[$Key])) { $IsValid=false; }
        }

        foreach (array('is_active','active','access_active') as $Key) {
            if (isset($Purchase[$Key])) { $IsValid=$this->ToBool($Purchase[$Key]); }
        }

        // A cancellation flag alone does not revoke a period that has already been paid.
        $Cancelled = false;
        foreach (array('is_canceled','is_cancelled') as $Key) { if (isset($Purchase[$Key]) && $this->ToBool($Purchase[$Key])) { $Cancelled=true; } }
        if ($Cancelled && $ValidUntil !== null && strtotime($ValidUntil . ' UTC') >= time()) { $IsValid=true; }

        if ($ValidUntil !== null && strtotime($ValidUntil . ' UTC') >= time() && $IsValid === null) { $IsValid=true; }

        if ($IsValid === null) {
            $OrderIsPaid = $this->First($Purchase,array('order_is_paid'));
            if ($OrderIsPaid !== null) { $IsValid=$this->ToBool($OrderIsPaid); }
        }

        if ($IsValid === null) {
            $AmountPaid = $this->First($Purchase,array('amount_paid','paid_amount','transaction_amount'));
            $IsValid = is_numeric($AmountPaid) && floatval($AmountPaid) > 0;
        }
        return array('valid'=>(bool)$IsValid,'status'=>($Event!==''?$Event:$Status),'valid_until'=>$ValidUntil);
    }

    public function VerifyIpnSignature($Payload)
    {
        $Secret = (string)get_option('kornsw_ps_ipn_secret','');
        $Received = '';
        if (isset($Payload['sha_sign'])) { $Received = strtoupper((string)$Payload['sha_sign']); }
        elseif (isset($Payload['SHASIGN'])) { $Received = strtoupper((string)$Payload['SHASIGN']); }

        if ($Secret === '' || $Received === '') {
            $this->LastIpnVerificationInfo = array('received'=>$Received,'expected'=>'','field_count'=>is_array($Payload)?count($Payload):0);
            return false;
        }

        $Parameters = is_array($Payload) ? $Payload : array();
        unset($Parameters['sha_sign']);
        unset($Parameters['SHASIGN']);

        // Digistore24 reference implementation (2026): case-sensitive SORT_STRING,
        // ignore empty/false values, append passphrase after every key=value pair.
        $Keys = array_keys($Parameters);
        $KeysToSort = array();
        foreach ($Keys as $Key) { $KeysToSort[] = strval($Key); }
        array_multisort($KeysToSort, SORT_STRING, $Keys);

        $ShaString = '';
        foreach ($Keys as $Key) {
            $Value = $Parameters[$Key];
            $IsEmpty = !isset($Value) || $Value === '' || $Value === false;
            if ($IsEmpty) { continue; }

            if (is_array($Value)) {
                $QueryString = http_build_query(array((string)$Key=>$Value), '', '&', PHP_QUERY_RFC3986);
                if ($QueryString === '') { continue; }
                foreach (explode('&', $QueryString) as $QueryPart) {
                    if ($QueryPart === '') { continue; }
                    $ShaString .= rawurldecode($QueryPart) . $Secret;
                }
                continue;
            }

            $ShaString .= strval($Key) . '=' . strval($Value) . $Secret;
        }

        $Expected = strtoupper(hash('sha512', $ShaString));
        $this->LastIpnVerificationInfo = array('received'=>$Received,'expected'=>$Expected,'field_count'=>count($Parameters));
        return hash_equals($Expected, $Received);
    }

    public function GetLastIpnVerificationInfo()
    {
        return $this->LastIpnVerificationInfo;
    }

    private function ExtractList($Data, $Candidates)
    {
        if (!is_array($Data)) { return array(); }
        foreach ($Candidates as $Key) { if (isset($Data[$Key]) && is_array($Data[$Key])) { return array_values($Data[$Key]); } }
        if (array_keys($Data) === range(0,count($Data)-1)) { return $Data; }
        foreach ($Data as $Value) { if (is_array($Value) && array_keys($Value) === range(0,count($Value)-1)) { return $Value; } }
        return array();
    }

    private function First($Array,$Keys)
    {
        foreach ($Keys as $Key) { if (array_key_exists($Key,$Array) && $Array[$Key] !== null && $Array[$Key] !== '') { return $Array[$Key]; } }
        return null;
    }

    private function ToBool($Value)
    {
        if (is_bool($Value)) { return $Value; }
        return in_array(strtolower((string)$Value),array('1','y','yes','true','active','on'),true);
    }

    private function NormalizeDate($Value)
    {
        if (!$Value) { return null; }
        $Ts = strtotime((string)$Value);
        return $Ts ? gmdate('Y-m-d H:i:s',$Ts) : null;
    }
}
