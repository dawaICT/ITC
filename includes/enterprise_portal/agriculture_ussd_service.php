<?php
declare(strict_types=1);

/**
 * Provider-neutral USSD session handling + local simulator.
 * Live shortcodes/credentials are optional; missing credentials must not block the portal.
 */

if (!function_exists('ep_ussd_start_session')) {
    /**
     * @return array{ok:bool,session_reference?:string,menu?:string,message?:string}
     */
    function ep_ussd_start_session(mysqli $db, string $phoneRaw, ?string $providerRequestId = null): array
    {
        if (!ep_agri_table_exists($db, 'enterprise_ussd_sessions')) {
            return ['ok' => false, 'message' => 'USSD schema not migrated.'];
        }
        $phone = ep_normalize_msisdn($phoneRaw);
        if ($phone === '') {
            return ['ok' => false, 'message' => 'Phone required.'];
        }
        if ($providerRequestId) {
            $dup = $db->prepare('SELECT session_reference FROM enterprise_ussd_sessions WHERE provider_request_id = ? LIMIT 1');
            if ($dup) {
                $dup->bind_param('s', $providerRequestId);
                $dup->execute();
                $row = $dup->get_result()->fetch_assoc();
                $dup->close();
                if ($row) {
                    return ['ok' => true, 'session_reference' => (string)$row['session_reference'], 'menu' => ep_ussd_main_menu(), 'message' => 'Idempotent replay.'];
                }
            }
        }

        $ref = 'USSD-' . strtoupper(bin2hex(random_bytes(6)));
        $reqLit = $providerRequestId ? ep_sql_quote_nullable($db, $providerRequestId) : 'NULL';
        $sql = sprintf(
            "INSERT INTO enterprise_ussd_sessions
            (session_reference, phone_e164, current_step, session_state_json, started_at, expires_at, completion_status, provider_request_id)
            VALUES ('%s', '%s', 'menu', JSON_OBJECT('step','menu'), NOW(), DATE_ADD(NOW(), INTERVAL 3 MINUTE), 'open', %s)",
            $db->real_escape_string($ref),
            $db->real_escape_string($phone),
            $reqLit
        );
        if (!$db->query($sql)) {
            return ['ok' => false, 'message' => $db->error];
        }
        return ['ok' => true, 'session_reference' => $ref, 'menu' => ep_ussd_main_menu()];
    }
}

if (!function_exists('ep_ussd_main_menu')) {
    function ep_ussd_main_menu(): string
    {
        return "Skills and Enterprise\n1. Check crop prices\n2. View my listings\n3. View buyer offers\n4. Accept or decline offer\n5. Request agent assistance";
    }
}

if (!function_exists('ep_ussd_handle_input')) {
    /**
     * @return array{ok:bool,response?:string,end?:bool,message?:string}
     */
    function ep_ussd_handle_input(mysqli $db, string $sessionRef, string $input): array
    {
        $stmt = $db->prepare("SELECT * FROM enterprise_ussd_sessions WHERE session_reference = ? LIMIT 1");
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Session lookup failed.'];
        }
        $stmt->bind_param('s', $sessionRef);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$session) {
            return ['ok' => false, 'message' => 'Unknown session.'];
        }
        if ((string)$session['completion_status'] !== 'open' || strtotime((string)$session['expires_at']) < time()) {
            return ['ok' => false, 'response' => 'Session expired. Dial again.', 'end' => true];
        }

        $choice = trim($input);
        $step = (string)$session['current_step'];
        if ($step === 'menu') {
            if ($choice === '1') {
                $prices = ep_current_commodity_prices($db, []);
                if ($prices === []) {
                    $text = 'No current verified prices. An SMS will be sent if available later.';
                } else {
                    $p = $prices[0];
                    $text = ep_format_price_sms($p) . "\nDetailed SMS will follow.";
                    ep_adapters()['sms']->send($db, (string)$session['phone_e164'], ep_format_price_sms($p), 'price_result');
                }
                $db->query("UPDATE enterprise_ussd_sessions SET completion_status='completed', current_step='done' WHERE id=" . (int)$session['id']);
                return ['ok' => true, 'response' => $text, 'end' => true];
            }
            if ($choice === '5') {
                $db->query("UPDATE enterprise_ussd_sessions SET completion_status='completed', current_step='agent' WHERE id=" . (int)$session['id']);
                ep_adapters()['sms']->send($db, (string)$session['phone_e164'], 'An agent will contact you about Skills and Enterprise support.', 'agent_request');
                return ['ok' => true, 'response' => 'Agent assistance requested. You will receive an SMS.', 'end' => true];
            }
            return ['ok' => true, 'response' => "Invalid option.\n" . ep_ussd_main_menu(), 'end' => false];
        }
        return ['ok' => true, 'response' => ep_ussd_main_menu(), 'end' => false];
    }
}
