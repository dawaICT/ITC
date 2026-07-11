<?php
// Institutional prefix for generated staff usernames / account IDs. Defined here
// (guarded) so id_helpers.php stays self-contained, and also in
// config/auth_constants.php for the rest of the app. Change it in ONE place to
// re-brand every generated and validated staff ID.
if (!defined('STAFF_USERNAME_PREFIX')) {
    define('STAFF_USERNAME_PREFIX', 'ITC');
}

// Position IDs are role identifiers such as LEC001 or ADM009, so they must
// remain broader than the new staff account number format.
if (!function_exists('validatePosId')) {
    function validatePosId($id) {
        return is_string($id) && preg_match('/^[A-Z]{3}\d{3,}$/', $id) === 1;
    }
}

// New staff account IDs use the institutional prefix only (STAFF_USERNAME_PREFIX).
if (!function_exists('validateStaffId')) {
    function validateStaffId($id) {
        $prefix = preg_quote(STAFF_USERNAME_PREFIX, '/');
        return is_string($id) && preg_match('/^' . $prefix . '\d{3}$/', $id) === 1;
    }
}

if (!function_exists('itc_generate_unique_id')) {
    /**
     * Generate the next available <prefix> + 3-digit account ID within
     * $table.$column. The prefix defaults to STAFF_USERNAME_PREFIX (ITC).
     * Legacy IDs using any other prefix are left untouched and not reused.
     */
    function itc_generate_unique_id(mysqli $db, string $table, string $column, string $prefix = STAFF_USERNAME_PREFIX) {
        $exists = function ($id) use ($db, $table, $column) {
            $stmt = $db->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
            if (!$stmt) { return false; }
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $stmt->store_result();
            $found = $stmt->num_rows > 0;
            $stmt->close();
            return $found;
        };

        // Derive the next sequence number from existing IDs that already use this
        // prefix. SUBSTRING starts after the prefix; the REGEXP anchors to it.
        $prefixLen = strlen($prefix);
        $prefixEsc = $db->real_escape_string($prefix);
        $sql = "SELECT MAX(CAST(SUBSTRING(`$column`, " . ($prefixLen + 1) . ") AS UNSIGNED)) AS max_n
                FROM `$table` WHERE `$column` REGEXP '^" . $prefixEsc . "[0-9]{3}$'";
        $res = $db->query($sql);
        $max = 0;
        if ($res && ($row = $res->fetch_assoc())) {
            $max = (int)($row['max_n'] ?? 0);
        }
        $next = max($max + 1, 1);
        do {
            $candidate = $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while ($exists($candidate));
        return $candidate;
    }
}

if (!function_exists('generateNextPosId')) {
    function generateNextPosId(mysqli $db) {
        return itc_generate_unique_id($db, 'positions', 'PosID');
    }
}

if (!function_exists('generateNextStaffId')) {
    function generateNextStaffId(mysqli $db) {
        return itc_generate_unique_id($db, 'staff', 'staff_id');
    }
}

if (!function_exists('resolveRoleModulePath')) {
    function resolveRoleModulePath(string $posId, string $posName = ''): string {
        // Direct legacy mappings
        $legacy = [
            'LEC001'     => 'lecturers',
            'LECTURER'   => 'lecturers',
            'ADM009'     => 'admin',
            'ADM010'     => 'admissions',
            'ADM005'     => 'admissions',
            'ADMIN'      => 'admin',
            'SUPER_ADMIN'=> 'admin',
            'REG008'     => 'admin',
            'HOD001'     => 'hod',
            'HOD007'     => 'hod',
            'DEN006'     => 'dean',
            'DEAN001'    => 'dean',
            'REG001'     => 'registrar',
            'LIB001'     => 'library',
            'WUC001'     => 'library',
            'VC002'      => 'vc',
            'DVC003'     => 'dvc',
            'ACC004'     => 'accounts',
        ];
        if (isset($legacy[$posId])) return $legacy[$posId];

        // Infer by role name keywords if provided
        $name = strtolower(trim($posName));
        if ($name !== '') {
            $map = [
                'super admin'          => 'admin',
                'superadmin'           => 'admin',
                'master admin'         => 'admin',
                'administrator'        => 'admin',
                'administration'       => 'admin',
                'administrative'       => 'admin',
                'systems admin'        => 'admin',
                'admin'                => 'admin',
                'lecturer'             => 'lecturers',
                'admissions'           => 'admissions',
                'admission'            => 'admissions',
                'head of department'   => 'hod',
                'hod'                  => 'hod',
                'dean'                 => 'dean',
                'registrar'            => 'registrar',
                'registry'             => 'registrar',
                'librarian'            => 'library',
                'library'              => 'library',
                'vice chancellor'      => 'vc',
                'deputy vice chancellor' => 'dvc',
                'accountant'           => 'accounts',
                'accounts'             => 'accounts',
                'finance'              => 'accounts',
                'bursar'               => 'accounts',
            ];
            foreach ($map as $needle => $path) {
                if (strpos($name, $needle) !== false) return $path;
            }
        }
        return 'index.php';
    }
}
