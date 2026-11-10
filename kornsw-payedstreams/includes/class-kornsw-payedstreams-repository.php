<?php
if (!defined('ABSPATH')) { exit; }

class KornSW_PayedStreams_Repository
{
    private function Table($Suffix)
    {
        global $wpdb;
        return $wpdb->prefix . 'kornsw_ps_' . $Suffix;
    }

    public function GetProducts($VisibleOnly = false)
    {
        global $wpdb;
        $Table = $this->Table('products');
        $Where = $VisibleOnly ? ' WHERE visible = 1' : '';
        return $wpdb->get_results("SELECT * FROM {$Table}{$Where} ORDER BY sort_order ASC, name ASC", ARRAY_A);
    }

    public function GetProduct($ExternalId)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->Table('products')} WHERE external_id=%s", $ExternalId), ARRAY_A);
    }

    public function UpsertProduct($Product)
    {
        global $wpdb;
        $Table = $this->Table('products');
        $Existing = $this->GetProduct($Product['external_id']);
        $IncomingPriceDisplay = isset($Product['price_display']) ? (string)$Product['price_display'] : '';
        $IncomingPriceAmount = isset($Product['price_amount']) && $Product['price_amount'] !== null ? $Product['price_amount'] : null;
        $IncomingCurrency = isset($Product['currency']) ? (string)$Product['currency'] : '';
        $Data = array(
            'external_id' => (string)$Product['external_id'],
            'name' => (string)$Product['name'],
            'description' => isset($Product['description']) ? (string)$Product['description'] : '',
            'price_display' => $IncomingPriceDisplay !== '' ? $IncomingPriceDisplay : ($Existing ? (string)$Existing['price_display'] : ''),
            'price_amount' => $IncomingPriceAmount !== null ? $IncomingPriceAmount : ($Existing && $Existing['price_amount'] !== null ? $Existing['price_amount'] : null),
            'currency' => $IncomingCurrency !== '' ? $IncomingCurrency : ($Existing ? (string)$Existing['currency'] : ''),
            'checkout_url' => isset($Product['checkout_url']) ? (string)$Product['checkout_url'] : '',
            'status' => isset($Product['status']) ? (string)$Product['status'] : '',
            'raw_json' => wp_json_encode($Product['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => gmdate('Y-m-d H:i:s'),
        );
        if ($Existing) {
            $wpdb->update($Table, $Data, array('external_id' => $Product['external_id']));
        } else {
            $wpdb->insert($Table, $Data);
        }
    }

    public function UpdateProductPresentation($ExternalId, $DescriptionOverride, $Visible, $Featured, $SortOrder)
    {
        global $wpdb;
        return $wpdb->update($this->Table('products'), array(
            'description_override' => $DescriptionOverride,
            'visible' => $Visible ? 1 : 0,
            'featured' => $Featured ? 1 : 0,
            'sort_order' => intval($SortOrder),
        ), array('external_id' => $ExternalId));
    }

    public function GetVideos()
    {
        global $wpdb;
        // content_date (UTC): the publication date delivered by the source, or - if the source
        // does not provide a usable one - the moment the content first appeared during a sync.
        return $wpdb->get_results(
            "SELECT *, COALESCE(published_at, first_seen_at, synced_at) AS content_date
             FROM {$this->Table('videos')}
             ORDER BY content_date DESC, title ASC",
            ARRAY_A
        );
    }

    public function GetVideo($ExternalId)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->Table('videos')} WHERE external_id=%s", $ExternalId), ARRAY_A);
    }

    public function UpsertVideo($Video)
    {
        global $wpdb;
        $Table = $this->Table('videos');
        $Now = gmdate('Y-m-d H:i:s');
        $Existing = $this->GetVideo($Video['external_id']);
        $PublishedAt = isset($Video['published_at']) ? $this->NormalizeUtcDateTime($Video['published_at']) : null;
        if ($PublishedAt === null && $Existing && !empty($Existing['published_at'])) {
            // Keep a previously known publication date if the source omits it this time.
            $PublishedAt = $Existing['published_at'];
        }
        $Data = array(
            'external_id' => (string)$Video['external_id'],
            'title' => (string)$Video['title'],
            'thumbnail_url' => isset($Video['thumbnail_url']) ? (string)$Video['thumbnail_url'] : '',
            'duration_seconds' => isset($Video['duration_seconds']) ? intval($Video['duration_seconds']) : 0,
            'status' => isset($Video['status']) ? (string)$Video['status'] : '',
            'source_type' => isset($Video['source_type']) ? (string)$Video['source_type'] : 'bunny',
            'source_ref' => isset($Video['source_ref']) ? (string)$Video['source_ref'] : '',
            'content_url' => isset($Video['content_url']) ? (string)$Video['content_url'] : '',
            'post_url' => isset($Video['post_url']) ? (string)$Video['post_url'] : '',
            'raw_json' => wp_json_encode($Video['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published_at' => $PublishedAt,
            'synced_at' => $Now,
        );
        if ($Existing) {
            if (empty($Existing['first_seen_at'])) { $Data['first_seen_at'] = $Now; }
            $wpdb->update($Table, $Data, array('external_id' => $Video['external_id']));
        } else {
            $Data['first_seen_at'] = $Now;
            $wpdb->insert($Table, $Data);
        }
    }

    /**
     * Normalizes a date/time value (string in UTC or unix timestamp) to 'Y-m-d H:i:s' (UTC).
     * Returns null for empty, invalid or implausible values (e.g. '0000-00-00 00:00:00').
     */
    private function NormalizeUtcDateTime($Value)
    {
        if ($Value === null || $Value === '' || $Value === false) { return null; }
        if (is_int($Value)) {
            $Timestamp = $Value;
        } else {
            $Text = trim((string)$Value);
            if ($Text === '' || strpos($Text, '0000-00-00') === 0) { return null; }
            try {
                $Parsed = new DateTimeImmutable($Text, new DateTimeZone('UTC'));
            } catch (Exception $Ex) {
                return null;
            }
            $Timestamp = $Parsed->getTimestamp();
        }
        // Reject obviously wrong values (before 2000 or more than one day in the future).
        if ($Timestamp < 946684800 || $Timestamp > time() + DAY_IN_SECONDS) { return null; }
        return gmdate('Y-m-d H:i:s', $Timestamp);
    }

    public function DeleteVideosBySource($SourceType)
    {
        global $wpdb;
        return $wpdb->delete($this->Table('videos'), array('source_type' => (string)$SourceType));
    }

    public function GetMappingsForVideo($VideoId)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->Table('mappings')} WHERE video_external_id=%s ORDER BY grant_type, grant_key", $VideoId), ARRAY_A);
    }

    public function GetMappingsByGrant($GrantType, $GrantKey)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->Table('mappings')} WHERE grant_type=%s AND grant_key=%s ORDER BY video_external_id", $GrantType, $GrantKey), ARRAY_A);
    }

    public function ReplaceMappingsForVideo($VideoId, $Mappings)
    {
        global $wpdb;
        $Table = $this->Table('mappings');
        $wpdb->delete($Table, array('video_external_id' => $VideoId));
        foreach ($Mappings as $Mapping) {
            if (empty($Mapping['grant_type']) || !isset($Mapping['grant_key'])) { continue; }
            $wpdb->insert($Table, array(
                'video_external_id' => $VideoId,
                'grant_type' => $Mapping['grant_type'],
                'grant_key' => (string)$Mapping['grant_key'],
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        }
    }

    public function ReplaceMappingsForGrant($GrantType, $GrantKey, $VideoIds)
    {
        global $wpdb;
        $Table = $this->Table('mappings');
        $wpdb->delete($Table, array('grant_type' => $GrantType, 'grant_key' => $GrantKey));
        foreach ($VideoIds as $VideoId) {
            $wpdb->insert($Table, array(
                'video_external_id' => $VideoId,
                'grant_type' => $GrantType,
                'grant_key' => $GrantKey,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        }
    }

    public function GetProductsForVideo($VideoId)
    {
        global $wpdb;
        $Products = $this->Table('products');
        $Mappings = $this->Table('mappings');
        return $wpdb->get_results($wpdb->prepare("SELECT p.* FROM {$Products} p INNER JOIN {$Mappings} m ON m.grant_type='product' AND m.grant_key=p.external_id WHERE m.video_external_id=%s AND p.visible=1 ORDER BY p.featured DESC, p.sort_order ASC, p.name ASC", $VideoId), ARRAY_A);
    }

    public function UpsertEntitlement($UserId, $ProductId, $IsValid, $Status, $ValidUntil, $Raw)
    {
        global $wpdb;
        $Table = $this->Table('entitlements');
        $Existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$Table} WHERE user_id=%d AND product_external_id=%s", $UserId, $ProductId));
        $Data = array(
            'user_id' => intval($UserId),
            'product_external_id' => (string)$ProductId,
            'source_status' => (string)$Status,
            'is_valid' => $IsValid ? 1 : 0,
            'valid_until' => $ValidUntil,
            'source_updated_at' => gmdate('Y-m-d H:i:s'),
            'checked_at' => gmdate('Y-m-d H:i:s'),
            'raw_json' => wp_json_encode($Raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        if ($Existing) { $wpdb->update($Table, $Data, array('id' => $Existing)); }
        else { $wpdb->insert($Table, $Data); }
    }

    public function GetValidProductIdsForUser($UserId)
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare("SELECT product_external_id FROM {$this->Table('entitlements')} WHERE user_id=%d AND is_valid=1 AND (valid_until IS NULL OR valid_until='' OR valid_until >= UTC_TIMESTAMP())", $UserId));
    }

    public function GetEntitlementsForUser($UserId)
    {
        global $wpdb;
        $Entitlements = $this->Table('entitlements');
        $Products = $this->Table('products');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, p.name AS product_name, p.price_display AS product_price_display\n             FROM {$Entitlements} e\n             LEFT JOIN {$Products} p ON p.external_id=e.product_external_id\n             WHERE e.user_id=%d\n             ORDER BY e.is_valid DESC, COALESCE(p.name,e.product_external_id) ASC",
            $UserId
        ), ARRAY_A);
    }

    public function GetMappedVideoCountForProduct($ProductId)
    {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT video_external_id) FROM {$this->Table('mappings')} WHERE grant_type='product' AND grant_key=%s",
            $ProductId
        )));
    }

    public function GetFirstPlayableVideoIdForProduct($ProductId)
    {
        global $wpdb;
        $Mappings = $this->Table('mappings');
        $Videos = $this->Table('videos');
        $Value = $wpdb->get_var($wpdb->prepare(
            "SELECT v.external_id
             FROM {$Mappings} m
             INNER JOIN {$Videos} v ON v.external_id=m.video_external_id
             WHERE m.grant_type='product'
               AND m.grant_key=%s
               AND v.source_type<>'post'
             ORDER BY v.title ASC
             LIMIT 1",
            $ProductId
        ));
        return $Value ? (string)$Value : '';
    }

    public function LinkBuyer($UserId, $BuyerId, $BuyerEmail)
    {
        global $wpdb;
        $Table = $this->Table('buyer_links');
        if ($BuyerId === null || $BuyerId === '') {
            $Exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$Table} WHERE user_id=%d AND buyer_email=%s LIMIT 1", $UserId, $BuyerEmail));
        } else {
            $Exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$Table} WHERE user_id=%d AND buyer_external_id=%s LIMIT 1", $UserId, $BuyerId));
        }
        $Data = array('user_id'=>intval($UserId),'buyer_external_id'=>(string)$BuyerId,'buyer_email'=>(string)$BuyerEmail,'updated_at'=>gmdate('Y-m-d H:i:s'));
        if ($Exists) { $wpdb->update($Table, $Data, array('id'=>$Exists)); }
        else { $Data['created_at']=gmdate('Y-m-d H:i:s'); $wpdb->insert($Table,$Data); }
    }

    public function GetProgress($UserId, $VideoId)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->Table('progress')} WHERE user_id=%d AND video_external_id=%s", $UserId, $VideoId), ARRAY_A);
    }

    public function SaveProgress($UserId, $VideoId, $Position, $Duration, $Qualified, $Completed)
    {
        global $wpdb;
        $Table = $this->Table('progress');
        $Existing = $this->GetProgress($UserId, $VideoId);
        $Now = gmdate('Y-m-d H:i:s');
        $MaxPosition = max(intval($Position), $Existing ? intval($Existing['max_position_seconds']) : 0);
        $Percent = $Duration > 0 ? min(100, round(($MaxPosition / $Duration) * 100, 2)) : 0;
        $IsCompleted = $Completed || $Percent >= 95 || ($Existing && intval($Existing['completed']) === 1);
        $ViewIncrement = 0;
        if ($Qualified) {
            $LastCounted = $Existing && !empty($Existing['last_view_counted_at']) ? strtotime($Existing['last_view_counted_at'] . ' UTC') : 0;
            if ($LastCounted === 0 || (time() - $LastCounted) >= 1800) { $ViewIncrement = 1; }
        }
        if ($ViewIncrement) {
            $ViewerHash = hash('sha256', 'user:' . intval($UserId) . ':' . wp_salt('auth'));
            $this->RecordViewEvent($VideoId, 'user', $ViewerHash);
        }
        if ($Existing) {
            $wpdb->update($Table, array(
                'last_viewed_at'=>$Now,
                'last_position_seconds'=>max(0,intval($Position)),
                'max_position_seconds'=>$MaxPosition,
                'progress_percent'=>$Percent,
                'completed'=>$IsCompleted ? 1 : 0,
                'qualified_views'=>intval($Existing['qualified_views']) + $ViewIncrement,
                'last_view_counted_at'=>$ViewIncrement ? $Now : $Existing['last_view_counted_at'],
            ), array('id'=>$Existing['id']));
        } else {
            $wpdb->insert($Table, array(
                'user_id'=>intval($UserId),'video_external_id'=>$VideoId,'first_started_at'=>$Now,'last_viewed_at'=>$Now,
                'last_position_seconds'=>max(0,intval($Position)),'max_position_seconds'=>$MaxPosition,'progress_percent'=>$Percent,
                'completed'=>$IsCompleted?1:0,'qualified_views'=>$ViewIncrement,'last_view_counted_at'=>$ViewIncrement?$Now:null,
            ));
        }
    }

    public function RecordViewEvent($VideoId, $ViewerType, $ViewerHash)
    {
        global $wpdb;
        $Bucket = gmdate('YmdHi', intval(floor(time() / 1800) * 1800));
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$this->Table('view_events')} (video_external_id,viewer_type,viewer_hash,view_bucket,occurred_at) VALUES (%s,%s,%s,%s,UTC_TIMESTAMP())", $VideoId, $ViewerType, $ViewerHash, $Bucket));
    }


    public function RecordContentClick($ContentId, $UserId = 0, $SessionHash = '')
    {
        global $wpdb;
        if ($UserId) { $ViewerHash = hash('sha256', 'user:' . intval($UserId) . ':' . wp_salt('auth')); $ViewerType = 'user_click'; }
        else { if ($SessionHash === '') { $SessionHash = wp_generate_uuid4(); } $ViewerHash = hash('sha256', $SessionHash . wp_salt('nonce')); $ViewerType = 'anonymous_click'; }
        $Bucket = gmdate('YmdHis') . '-' . substr(wp_generate_password(8, false, false), 0, 8);
        $wpdb->insert($this->Table('view_events'), array('video_external_id'=>$ContentId,'viewer_type'=>$ViewerType,'viewer_hash'=>$ViewerHash,'view_bucket'=>$Bucket,'occurred_at'=>gmdate('Y-m-d H:i:s')));
    }

    public function RecordAnonymousView($VideoId, $SessionHash)
    {
        $this->RecordViewEvent($VideoId, 'anonymous', $SessionHash);
    }

    public function GetVideoStats($VideoId, $Days)
    {
        global $wpdb;
        $Events = $this->Table('view_events');
        $Progress = $this->Table('progress');
        $Since = gmdate('Y-m-d H:i:s', time() - (intval($Days) * DAY_IN_SECONDS));
        $EventData = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) views, COUNT(DISTINCT viewer_hash) users FROM {$Events} WHERE video_external_id=%s AND occurred_at >= %s", $VideoId, $Since), ARRAY_A);
        $ProgressData = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(AVG(progress_percent),0) avg_progress FROM {$Progress} WHERE video_external_id=%s AND last_viewed_at >= %s", $VideoId, $Since), ARRAY_A);
        return array(
            'views' => intval($EventData['views']),
            'unique_users' => intval($EventData['users']),
            'avg_progress' => round(floatval($ProgressData['avg_progress']), 1),
        );
    }
}

