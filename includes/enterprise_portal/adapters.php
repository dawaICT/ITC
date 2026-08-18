<?php
declare(strict_types=1);

/**
 * Replaceable adapters for integrated vs standalone deployment.
 * Agriculture services must call these instead of hard-coding Academic Portal APIs.
 */

final class EnterpriseIdentityAdapter
{
    public function currentUserId(): int
    {
        return function_exists('ep_current_user_id') ? ep_current_user_id() : (int)($_SESSION['user_id'] ?? 0);
    }

    public function currentActorLabel(): string
    {
        $label = '';
        if (function_exists('ep_current_actor')) {
            $label = trim((string)ep_current_actor());
        }
        if ($label === '') {
            $uid = $this->currentUserId();
            $label = $uid > 0 ? ('user:' . $uid) : 'system';
        }
        return $label;
    }

    /**
     * Create or resolve a channel-only portal user for phone-bound farmers.
     * Does not create a second auth stack — uses existing users table when present.
     *
     * @return array{ok:bool,user_id?:int,created?:bool,message?:string}
     */
    public function resolveOrCreatePhoneUser(mysqli $db, string $msisdn, string $displayName = ''): array
    {
        $phone = ep_normalize_msisdn($msisdn);
        if ($phone === '' || strlen($phone) < 10) {
            return ['ok' => false, 'message' => 'Valid mobile number is required.'];
        }

        // Prefer communication_channels linkage when table exists.
        if ($this->tableExists($db, 'enterprise_communication_channels')) {
            $stmt = $db->prepare("SELECT user_id FROM enterprise_communication_channels
                WHERE channel_type IN ('sms','ussd','phone') AND phone_e164 = ? AND user_id IS NOT NULL
                ORDER BY is_primary DESC, id ASC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $phone);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && (int)$row['user_id'] > 0) {
                    return ['ok' => true, 'user_id' => (int)$row['user_id'], 'created' => false];
                }
            }
        }

        if (!$this->tableExists($db, 'users')) {
            return ['ok' => false, 'message' => 'Users table unavailable.'];
        }

        $username = 'farm_' . $phone;
        $chk = $db->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
        if ($chk) {
            $chk->bind_param('s', $username);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($existing) {
                return ['ok' => true, 'user_id' => (int)$existing['user_id'], 'created' => false];
            }
        }

        $pin = (string)random_int(100000, 999999);
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $name = trim($displayName) !== '' ? trim($displayName) : ('Farmer ' . substr($phone, -4));
        $role = 'enterprise_participant';

        // Schema varies — try common columns.
        $cols = $this->userColumns($db);
        if (!isset($cols['username']) || !isset($cols['password'])) {
            return ['ok' => false, 'message' => 'Users schema missing username/password.'];
        }

        if (isset($cols['full_name'])) {
            $ins = $db->prepare('INSERT INTO users (username, password, full_name) VALUES (?,?,?)');
            if (!$ins) {
                return ['ok' => false, 'message' => 'Cannot create user.'];
            }
            $ins->bind_param('sss', $username, $hash, $name);
        } else {
            $ins = $db->prepare('INSERT INTO users (username, password) VALUES (?,?)');
            if (!$ins) {
                return ['ok' => false, 'message' => 'Cannot create user.'];
            }
            $ins->bind_param('ss', $username, $hash);
        }
        if (!$ins->execute()) {
            $err = $ins->error;
            $ins->close();
            return ['ok' => false, 'message' => 'User create failed: ' . $err];
        }
        $userId = (int)$ins->insert_id;
        $ins->close();

        // Store initial PIN hint only in audit — never SMS the permanent PIN in production without reset flow.
        if (function_exists('ep_audit')) {
            ep_audit($db, 'enterprise_portal.phone_user_created', [
                'user_id' => $userId,
                'phone_e164' => $phone,
                'note' => 'Channel-only farmer user; PIN set at creation (deliver via secure channel).',
            ]);
        }

        return ['ok' => true, 'user_id' => $userId, 'created' => true, 'temp_pin' => $pin];
    }

    /** @return array<string,true> */
    private function userColumns(mysqli $db): array
    {
        $out = [];
        $res = @$db->query('SHOW COLUMNS FROM users');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[(string)$row['Field']] = true;
            }
            $res->free();
        }
        return $out;
    }

    private function tableExists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $ok;
    }
}

final class EnterpriseStudentDataAdapter
{
    public function resolveStudentId(): ?string
    {
        $sid = trim((string)($_SESSION['Sid'] ?? ''));
        return $sid !== '' ? $sid : null;
    }
}

final class EnterpriseNotificationAdapter
{
    public function notify(mysqli $db, string $userId, string $role, string $title, string $message, string $actionUrl = '', string $alertType = 'enterprise_event'): void
    {
        if (function_exists('ep_notify_user')) {
            ep_notify_user($db, $userId, $role, $title, $message, $actionUrl, $alertType);
            return;
        }
        if (function_exists('wuc_notify_portal')) {
            wuc_notify_portal($db, [
                'user_id' => $userId,
                'user_role' => $role,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'alert_type' => $alertType,
                'module' => 'enterprise_portal',
            ]);
        }
    }
}

final class EnterpriseAuditAdapter
{
    public function log(mysqli $db, string $action, array $details = []): void
    {
        if (function_exists('ep_audit')) {
            ep_audit($db, $action, $details);
            return;
        }
        if (function_exists('audit_log_current_user')) {
            audit_log_current_user($db, $action, array_merge(['module' => 'enterprise_portal'], $details));
        }
    }
}

final class EnterpriseSmsProviderAdapter
{
    public function providerName(): string
    {
        $p = function_exists('wuc_portal_env')
            ? wuc_portal_env('ENTERPRISE_SMS_PROVIDER', 'mock')
            : (getenv('ENTERPRISE_SMS_PROVIDER') ?: 'mock');
        return strtolower(trim((string)$p)) ?: 'mock';
    }

    /**
     * @return array{ok:bool,provider_reference?:string,message?:string,queued?:bool}
     */
    public function send(mysqli $db, string $phoneE164, string $body, string $messageType = 'general', ?string $idempotencyKey = null): array
    {
        $phone = ep_normalize_msisdn($phoneE164);
        if ($phone === '' || trim($body) === '') {
            return ['ok' => false, 'message' => 'Phone and message body are required.'];
        }
        if (mb_strlen($body) > 480) {
            return ['ok' => false, 'message' => 'SMS body too long.'];
        }

        $provider = $this->providerName();
        $ref = $idempotencyKey !== null && $idempotencyKey !== ''
            ? $idempotencyKey
            : ('sms_' . bin2hex(random_bytes(8)));

        if ($this->tableExists($db, 'enterprise_sms_messages') && $idempotencyKey) {
            $stmt = $db->prepare('SELECT id, delivery_status FROM enterprise_sms_messages WHERE provider_reference = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $ref);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($dup) {
                    return ['ok' => true, 'provider_reference' => $ref, 'queued' => false, 'message' => 'Idempotent replay.'];
                }
            }
        }

        // Queue row — never send synchronously from web requests in production path.
        if ($this->tableExists($db, 'enterprise_sms_messages')) {
            $status = 'queued';
            $dir = 'outbound';
            $ins = $db->prepare('INSERT INTO enterprise_sms_messages
                (user_id, phone_number, direction, message_type, message_text, provider_name, provider_reference, delivery_status, created_at)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, NOW())');
            if ($ins) {
                $ins->bind_param('sssssss', $phone, $dir, $messageType, $body, $provider, $ref, $status);
                $ins->execute();
                $ins->close();
            }
        }

        if ($provider === 'mock' || $provider === '') {
            $this->markDeliveredMock($db, $ref);
            return ['ok' => true, 'provider_reference' => $ref, 'queued' => true, 'message' => 'Mock SMS queued.'];
        }

        // Live providers are configured later; missing credentials must not break the portal.
        return ['ok' => true, 'provider_reference' => $ref, 'queued' => true, 'message' => 'SMS queued for provider ' . $provider];
    }

    private function markDeliveredMock(mysqli $db, string $ref): void
    {
        if (!$this->tableExists($db, 'enterprise_sms_messages')) {
            return;
        }
        $st = 'mock_delivered';
        $stmt = $db->prepare('UPDATE enterprise_sms_messages SET delivery_status = ?, sent_at = NOW() WHERE provider_reference = ?');
        if ($stmt) {
            $stmt->bind_param('ss', $st, $ref);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function tableExists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $res = @$db->query("SHOW TABLES LIKE '{$safe}'");
        $ok = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
        return $ok;
    }
}

final class EnterpriseUssdProviderAdapter
{
    public function providerName(): string
    {
        $p = function_exists('wuc_portal_env')
            ? wuc_portal_env('ENTERPRISE_USSD_PROVIDER', 'simulator')
            : (getenv('ENTERPRISE_USSD_PROVIDER') ?: 'simulator');
        return strtolower(trim((string)$p)) ?: 'simulator';
    }

    public function credentialsConfigured(): bool
    {
        $secret = function_exists('wuc_portal_env')
            ? wuc_portal_env('ENTERPRISE_USSD_WEBHOOK_SECRET', '')
            : (getenv('ENTERPRISE_USSD_WEBHOOK_SECRET') ?: '');
        return $this->providerName() !== 'simulator' && trim((string)$secret) !== '';
    }

    /**
     * Verify gateway signature when live credentials exist; simulator always accepts.
     */
    public function verifySignature(string $payload, string $signatureHeader): bool
    {
        if (!$this->credentialsConfigured()) {
            return true;
        }
        $secret = (string)(function_exists('wuc_portal_env')
            ? wuc_portal_env('ENTERPRISE_USSD_WEBHOOK_SECRET', '')
            : getenv('ENTERPRISE_USSD_WEBHOOK_SECRET'));
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signatureHeader);
    }
}

if (!function_exists('ep_adapters')) {
    /**
     * @return array{
     *   identity:EnterpriseIdentityAdapter,
     *   student:EnterpriseStudentDataAdapter,
     *   notify:EnterpriseNotificationAdapter,
     *   audit:EnterpriseAuditAdapter,
     *   sms:EnterpriseSmsProviderAdapter,
     *   ussd:EnterpriseUssdProviderAdapter
     * }
     */
    function ep_adapters(): array
    {
        static $bag = null;
        if ($bag === null) {
            $bag = [
                'identity' => new EnterpriseIdentityAdapter(),
                'student' => new EnterpriseStudentDataAdapter(),
                'notify' => new EnterpriseNotificationAdapter(),
                'audit' => new EnterpriseAuditAdapter(),
                'sms' => new EnterpriseSmsProviderAdapter(),
                'ussd' => new EnterpriseUssdProviderAdapter(),
            ];
        }
        return $bag;
    }
}
