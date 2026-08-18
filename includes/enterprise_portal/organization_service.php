<?php
declare(strict_types=1);

/**
 * Multi-organization context (Stage 2 foundation).
 * Integrated mode: default host organization; WUCPortal remains an identity adapter.
 */

if (!function_exists('ep_organization_types')) {
    /** @return list<string> */
    function ep_organization_types(): array
    {
        return [
            'training_institution',
            'cooperative',
            'employer',
            'business',
            'crop_buyer',
            'government_agency',
            'ngo',
            'financial_partner',
            'industry_association',
            'platform_operator',
        ];
    }
}

if (!function_exists('ep_organizations_table_ready')) {
    function ep_organizations_table_ready(mysqli $db): bool
    {
        return function_exists('ep_agri_table_exists')
            ? ep_agri_table_exists($db, 'enterprise_organizations')
            : (bool)@$db->query("SHOW TABLES LIKE 'enterprise_organizations'")->num_rows;
    }
}

if (!function_exists('ep_get_default_organization_id')) {
    function ep_get_default_organization_id(mysqli $db): ?int
    {
        if (!ep_organizations_table_ready($db)) {
            return null;
        }
        if (function_exists('ep_setting')) {
            $lit = ep_setting($db, 'primary_organization_id', '');
            if ($lit !== null && $lit !== '' && ctype_digit($lit)) {
                return (int)$lit;
            }
        }
        $res = $db->query("SELECT id FROM enterprise_organizations WHERE org_code = 'DEFAULT' LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return (int)$row['id'];
        }
        return null;
    }
}

if (!function_exists('ep_current_organization_id')) {
    /**
     * Session-selected workspace org, else default host org, else null (legacy single-tenant).
     */
    function ep_current_organization_id(mysqli $db): ?int
    {
        $sess = (int)($_SESSION['enterprise_organization_id'] ?? 0);
        if ($sess > 0) {
            return $sess;
        }
        return ep_get_default_organization_id($db);
    }
}

if (!function_exists('ep_set_current_organization_id')) {
    function ep_set_current_organization_id(int $organizationId): void
    {
        if ($organizationId > 0) {
            $_SESSION['enterprise_organization_id'] = $organizationId;
        } else {
            unset($_SESSION['enterprise_organization_id']);
        }
    }
}

if (!function_exists('ep_get_organization')) {
    function ep_get_organization(mysqli $db, int $organizationId): ?array
    {
        if (!ep_organizations_table_ready($db) || $organizationId <= 0) {
            return null;
        }
        $stmt = $db->prepare('SELECT * FROM enterprise_organizations WHERE id = ? AND status = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $active = 'active';
        $stmt->bind_param('is', $organizationId, $active);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ep_user_organization_ids')) {
    /**
     * Organizations linked to a user (for future workspace switcher).
     *
     * @return list<int>
     */
    function ep_user_organization_ids(mysqli $db, int $userId): array
    {
        if ($userId <= 0 || !function_exists('ep_agri_table_exists') || !ep_agri_table_exists($db, 'enterprise_organization_users')) {
            $def = ep_get_default_organization_id($db);
            return $def !== null ? [$def] : [];
        }
        $stmt = $db->prepare('SELECT organization_id FROM enterprise_organization_users WHERE user_id = ? AND status = ?');
        if (!$stmt) {
            return [];
        }
        $st = 'active';
        $stmt->bind_param('is', $userId, $st);
        $stmt->execute();
        $res = $stmt->get_result();
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['organization_id'];
        }
        $stmt->close();
        if ($ids === []) {
            $def = ep_get_default_organization_id($db);
            if ($def !== null) {
                $ids[] = $def;
            }
        }
        return $ids;
    }
}

if (!function_exists('ep_can_manage_organizations')) {
    function ep_can_manage_organizations(mysqli $db): bool
    {
        if (function_exists('ep_is_systems_admin') && ep_is_systems_admin()) {
            return true;
        }
        return function_exists('ep_staff_can') && ep_staff_can($db, 'enterprise.organizations.manage');
    }
}

if (!function_exists('ep_user_can_access_organization')) {
    function ep_user_can_access_organization(mysqli $db, int $userId, int $organizationId): bool
    {
        if ($organizationId <= 0) {
            return false;
        }
        if (ep_can_manage_organizations($db)) {
            return ep_get_organization($db, $organizationId) !== null;
        }
        return in_array($organizationId, ep_user_organization_ids($db, $userId), true);
    }
}

if (!function_exists('ep_ensure_organization_context')) {
    /**
     * Validates session org; picks default when user has exactly one org.
     */
    function ep_ensure_organization_context(mysqli $db, int $userId): void
    {
        if (!ep_organizations_table_ready($db)) {
            return;
        }
        $current = (int)($_SESSION['enterprise_organization_id'] ?? 0);
        if ($current > 0 && ep_user_can_access_organization($db, $userId, $current)) {
            return;
        }
        $ids = ep_user_organization_ids($db, $userId);
        if ($ids === []) {
            unset($_SESSION['enterprise_organization_id']);
            return;
        }
        if (count($ids) === 1) {
            ep_set_current_organization_id($ids[0]);
            return;
        }
        if ($current > 0 && !ep_user_can_access_organization($db, $userId, $current)) {
            unset($_SESSION['enterprise_organization_id']);
        }
    }
}

if (!function_exists('ep_list_accessible_organizations')) {
    /**
     * @return list<array<string,mixed>>
     */
    function ep_list_accessible_organizations(mysqli $db, int $userId): array
    {
        if (!ep_organizations_table_ready($db)) {
            return [];
        }
        if (ep_can_manage_organizations($db)) {
            $res = $db->query("SELECT * FROM enterprise_organizations WHERE status = 'active' ORDER BY org_name ASC");
            $rows = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }
        $ids = ep_user_organization_ids($db, $userId);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $sql = "SELECT * FROM enterprise_organizations WHERE id IN ({$placeholders}) AND status = 'active' ORDER BY org_name ASC";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ep_generate_org_code')) {
    function ep_generate_org_code(string $prefix = 'ORG'): string
    {
        return $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}

if (!function_exists('ep_create_organization')) {
    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,organization_id?:int,org_code?:string,message?:string}
     */
    function ep_create_organization(mysqli $db, array $data): array
    {
        if (!ep_organizations_table_ready($db)) {
            return ['ok' => false, 'message' => 'Organization schema not migrated.'];
        }
        $name = trim((string)($data['org_name'] ?? ''));
        $type = trim((string)($data['org_type'] ?? ''));
        if ($name === '' || !in_array($type, ep_organization_types(), true)) {
            return ['ok' => false, 'message' => 'Organization name and valid type are required.'];
        }
        $code = trim((string)($data['org_code'] ?? '')) ?: ep_generate_org_code();
        $province = trim((string)($data['province'] ?? ''));
        $district = trim((string)($data['district'] ?? ''));
        $email = trim((string)($data['contact_email'] ?? ''));
        $phone = trim((string)($data['contact_phone'] ?? ''));

        $stmt = $db->prepare('INSERT INTO enterprise_organizations (org_code, org_name, org_type, province, district, contact_email, contact_phone, status) VALUES (?,?,?,?,?,?,?,?)');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Could not create organization.'];
        }
        $status = 'active';
        $stmt->bind_param('ssssssss', $code, $name, $type, $province, $district, $email, $phone, $status);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['ok' => false, 'message' => 'Create failed: ' . $err];
        }
        $id = (int)$stmt->insert_id;
        $stmt->close();
        ep_adapters()['audit']->log($db, 'enterprise.organization_created', ['organization_id' => $id, 'org_code' => $code, 'org_type' => $type]);
        return ['ok' => true, 'organization_id' => $id, 'org_code' => $code];
    }
}

if (!function_exists('ep_link_user_to_organization')) {
    function ep_link_user_to_organization(mysqli $db, int $organizationId, int $userId, string $roleInOrg = 'officer'): array
    {
        if (!ep_organizations_table_ready($db) || $organizationId <= 0 || $userId <= 0) {
            return ['ok' => false, 'message' => 'Invalid organization or user.'];
        }
        if (ep_get_organization($db, $organizationId) === null) {
            return ['ok' => false, 'message' => 'Organization not found.'];
        }
        $stmt = $db->prepare('INSERT INTO enterprise_organization_users (organization_id, user_id, role_in_org, status) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE role_in_org = VALUES(role_in_org), status = VALUES(status)');
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Link failed.'];
        }
        $st = 'active';
        $stmt->bind_param('iiss', $organizationId, $userId, $roleInOrg, $st);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            return ['ok' => false, 'message' => $err];
        }
        $stmt->close();
        ep_adapters()['audit']->log($db, 'enterprise.organization_user_linked', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'role_in_org' => $roleInOrg,
        ]);
        return ['ok' => true];
    }
}

if (!function_exists('ep_scope_organization_id')) {
    /** Organization filter for tenant-scoped queries; null when schema/org unavailable. */
    function ep_scope_organization_id(mysqli $db): ?int
    {
        if (!ep_organizations_table_ready($db)) {
            return null;
        }
        return ep_current_organization_id($db);
    }
}

if (!function_exists('ep_record_belongs_to_scope')) {
    function ep_record_belongs_to_scope(mysqli $db, ?int $recordOrgId): bool
    {
        $scope = ep_scope_organization_id($db);
        if ($scope === null) {
            return true;
        }
        if ($recordOrgId === null || $recordOrgId === 0) {
            return ep_can_manage_organizations($db);
        }
        return (int)$recordOrgId === (int)$scope;
    }
}
