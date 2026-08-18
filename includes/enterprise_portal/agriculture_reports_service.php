<?php
declare(strict_types=1);

/**
 * Agriculture market-access report counters.
 * Never counts price checks, matches, or accepted offers as completed sales.
 */

if (!function_exists('ep_agriculture_report_metrics')) {
    /**
     * @return array<string,int|float>
     */
    function ep_agriculture_report_metrics(mysqli $db): array
    {
        $m = [
            'farmers_registered' => 0,
            'farmers_verified' => 0,
            'produce_listed' => 0,
            'produce_available' => 0,
            'buyer_demands' => 0,
            'matches_suggested' => 0,
            'matches_approved' => 0,
            'offers_accepted' => 0,
            'produce_promised' => 0,
            'produce_delivered' => 0,
            'sales_confirmed' => 0,
            'price_checks_sms' => 0,
            'sms_delivered' => 0,
            'ussd_completed' => 0,
            'expired_prices' => 0,
            'expired_produce' => 0,
        ];
        if (!function_exists('ep_agri_table_exists') || !ep_agri_table_exists($db, 'enterprise_farmer_profiles')) {
            return $m;
        }

        $queries = [
            'farmers_registered' => 'SELECT COUNT(*) c FROM enterprise_farmer_profiles',
            'farmers_verified' => "SELECT COUNT(*) c FROM enterprise_farmer_profiles WHERE verification_level <> 'unverified'",
            'produce_listed' => 'SELECT COUNT(*) c FROM enterprise_produce_listings',
            'produce_available' => "SELECT COUNT(*) c FROM enterprise_produce_listings WHERE status='active' AND availability_status='available' AND expires_at > NOW()",
            'buyer_demands' => 'SELECT COUNT(*) c FROM enterprise_buyer_crop_demands',
            'matches_suggested' => 'SELECT COUNT(*) c FROM enterprise_produce_matches',
            'matches_approved' => "SELECT COUNT(*) c FROM enterprise_produce_matches WHERE officer_decision='approved'",
            'offers_accepted' => "SELECT COUNT(*) c FROM enterprise_produce_matches WHERE farmer_consent_status='accepted'",
            'produce_promised' => "SELECT COUNT(*) c FROM enterprise_produce_matches WHERE outcome_status='promised'",
            'produce_delivered' => "SELECT COUNT(*) c FROM enterprise_produce_matches WHERE outcome_status='delivered'",
            'sales_confirmed' => "SELECT COUNT(*) c FROM enterprise_produce_matches WHERE outcome_status='sale_confirmed'",
            'price_checks_sms' => "SELECT COUNT(*) c FROM enterprise_sms_messages WHERE message_type='price_result'",
            'sms_delivered' => "SELECT COUNT(*) c FROM enterprise_sms_messages WHERE delivery_status IN ('delivered','mock_delivered')",
            'ussd_completed' => "SELECT COUNT(*) c FROM enterprise_ussd_sessions WHERE completion_status='completed'",
            'expired_prices' => "SELECT COUNT(*) c FROM enterprise_commodity_prices WHERE publication_status='published' AND effective_to IS NOT NULL AND effective_to < CURDATE()",
            'expired_produce' => "SELECT COUNT(*) c FROM enterprise_produce_listings WHERE status='expired' OR expires_at <= NOW()",
        ];
        foreach ($queries as $key => $sql) {
            $r = @$db->query($sql);
            if ($r) {
                $m[$key] = (int)($r->fetch_assoc()['c'] ?? 0);
            }
        }
        return $m;
    }
}
