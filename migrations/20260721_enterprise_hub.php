<?php
declare(strict_types=1);

/**
 * Skills-to-Trade and Investment Hub (enterprise_hub)
 *
 * Additive migration only. Creates module tables, seeds categories,
 * registers RBAC permissions, and stores module settings.
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function eh_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $exists;
}

function eh_exec(mysqli $db, string $sql): void
{
    if (!$db->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $db->error . "\n" . $sql);
    }
}

$db->begin_transaction();

try {
    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(120) NOT NULL,
            category_slug VARCHAR(140) NOT NULL,
            department_id INT NULL,
            description VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_eh_category_slug (category_slug),
            KEY idx_eh_category_active (is_active, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_user_id INT NOT NULL,
            student_id VARCHAR(50) NULL,
            business_name VARCHAR(180) NOT NULL,
            profile_type VARCHAR(40) NOT NULL,
            description TEXT NULL,
            province VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            public_phone VARCHAR(40) NULL,
            public_email VARCHAR(120) NULL,
            business_registration_status VARCHAR(40) NOT NULL DEFAULT 'not_registered',
            registration_number VARCHAR(80) NULL,
            years_operating INT NULL,
            programme_code VARCHAR(50) NULL,
            programme_name VARCHAR(180) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            KEY idx_eh_profile_owner (owner_user_id),
            KEY idx_eh_profile_student (student_id),
            KEY idx_eh_profile_status (status),
            KEY idx_eh_profile_type (profile_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_profile_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            item_type VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            slug VARCHAR(220) NOT NULL,
            short_description VARCHAR(500) NULL,
            full_description MEDIUMTEXT NULL,
            unit_price DECIMAL(14,2) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'ZMW',
            current_capacity INT NULL,
            capacity_period VARCHAR(40) NULL,
            investment_required DECIMAL(14,2) NULL,
            investment_purpose VARCHAR(500) NULL,
            expected_capacity INT NULL,
            expected_capacity_period VARCHAR(40) NULL,
            employment_potential INT NULL,
            public_code VARCHAR(32) NOT NULL,
            readiness_score DECIMAL(5,2) NULL,
            readiness_level VARCHAR(60) NULL,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            view_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            lecturer_verified_at DATETIME NULL,
            approved_at DATETIME NULL,
            published_at DATETIME NULL,
            unpublished_at DATETIME NULL,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            UNIQUE KEY uq_eh_item_public_code (public_code),
            UNIQUE KEY uq_eh_item_slug (slug),
            KEY idx_eh_item_status (status),
            KEY idx_eh_item_category (category_id),
            KEY idx_eh_item_profile (enterprise_profile_id),
            KEY idx_eh_item_published (status, published_at),
            KEY idx_eh_item_type (item_type),
            KEY idx_eh_item_featured (is_featured, status),
            CONSTRAINT fk_eh_item_profile FOREIGN KEY (enterprise_profile_id)
                REFERENCES enterprise_profiles(id) ON DELETE RESTRICT,
            CONSTRAINT fk_eh_item_category FOREIGN KEY (category_id)
                REFERENCES enterprise_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_item_media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_item_id BIGINT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            media_type VARCHAR(30) NOT NULL DEFAULT 'image',
            caption VARCHAR(255) NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            uploaded_by VARCHAR(50) NOT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_eh_media_item (enterprise_item_id, sort_order),
            KEY idx_eh_media_primary (enterprise_item_id, is_primary),
            CONSTRAINT fk_eh_media_item FOREIGN KEY (enterprise_item_id)
                REFERENCES enterprise_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_costs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_item_id BIGINT UNSIGNED NOT NULL,
            material_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            labour_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            transport_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            utilities_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            packaging_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            marketing_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            other_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            number_of_units INT NOT NULL DEFAULT 1,
            total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            cost_per_unit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            selling_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            profit_per_unit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            expected_revenue DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            expected_profit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            profit_margin DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            break_even_quantity INT NULL,
            calculation_version INT NOT NULL DEFAULT 1,
            calculated_at DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_eh_cost_item (enterprise_item_id),
            CONSTRAINT fk_eh_cost_item FOREIGN KEY (enterprise_item_id)
                REFERENCES enterprise_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_readiness_assessments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_item_id BIGINT UNSIGNED NOT NULL,
            product_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            market_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            costing_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            capacity_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            compliance_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            team_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            total_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            readiness_level VARCHAR(60) NOT NULL,
            recommendations_json JSON NULL,
            completed_by VARCHAR(50) NOT NULL,
            assessed_at DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_eh_readiness_item (enterprise_item_id),
            CONSTRAINT fk_eh_readiness_item FOREIGN KEY (enterprise_item_id)
                REFERENCES enterprise_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_reviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_item_id BIGINT UNSIGNED NOT NULL,
            reviewer_id VARCHAR(50) NOT NULL,
            review_stage VARCHAR(30) NOT NULL,
            decision VARCHAR(40) NOT NULL,
            comments TEXT NULL,
            previous_status VARCHAR(40) NOT NULL,
            resulting_status VARCHAR(40) NOT NULL,
            reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_eh_review_item (enterprise_item_id, reviewed_at),
            KEY idx_eh_review_reviewer (reviewer_id),
            CONSTRAINT fk_eh_review_item FOREIGN KEY (enterprise_item_id)
                REFERENCES enterprise_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_interests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_item_id BIGINT UNSIGNED NOT NULL,
            visitor_name VARCHAR(120) NOT NULL,
            organization VARCHAR(180) NULL,
            email VARCHAR(160) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            interest_type VARCHAR(60) NOT NULL,
            investment_range VARCHAR(80) NULL,
            quantity_requested INT NULL,
            message TEXT NOT NULL,
            preferred_contact_method VARCHAR(40) NULL,
            follow_up_status VARCHAR(40) NOT NULL DEFAULT 'new',
            assigned_to VARCHAR(50) NULL,
            internal_notes TEXT NULL,
            consent_accepted TINYINT(1) NOT NULL DEFAULT 0,
            source VARCHAR(60) NOT NULL DEFAULT 'showcase',
            ip_hash CHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            KEY idx_eh_interest_item (enterprise_item_id),
            KEY idx_eh_interest_status (follow_up_status),
            KEY idx_eh_interest_type (interest_type),
            KEY idx_eh_interest_assigned (assigned_to),
            CONSTRAINT fk_eh_interest_item FOREIGN KEY (enterprise_item_id)
                REFERENCES enterprise_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_eh_setting_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    eh_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_interest_rate_limits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ip_hash CHAR(64) NOT NULL,
            email_hash CHAR(64) NOT NULL,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_eh_rate_ip (ip_hash, submitted_at),
            KEY idx_eh_rate_email (email_hash, submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Seed categories
    $categories = [
        ['Agriculture and Agro-processing', 'agriculture-agro-processing', 10],
        ['Automotive', 'automotive', 20],
        ['Construction', 'construction', 30],
        ['Electrical', 'electrical', 40],
        ['Electronics', 'electronics', 50],
        ['Fabrication and Welding', 'fabrication-welding', 60],
        ['Furniture and Carpentry', 'furniture-carpentry', 70],
        ['ICT and Digital Services', 'ict-digital-services', 80],
        ['Plumbing', 'plumbing', 90],
        ['Renewable Energy', 'renewable-energy', 100],
        ['Tailoring and Fashion', 'tailoring-fashion', 110],
        ['Transport and Logistics', 'transport-logistics', 120],
        ['Food Production', 'food-production', 130],
        ['General Business Services', 'general-business-services', 140],
    ];
    $catStmt = $db->prepare("
        INSERT INTO enterprise_categories (category_name, category_slug, description, is_active, display_order)
        VALUES (?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE category_name = VALUES(category_name), display_order = VALUES(display_order), is_active = 1
    ");
    foreach ($categories as $cat) {
        $desc = $cat[0] . ' opportunities from ITC training programmes.';
        $catStmt->bind_param('sssi', $cat[0], $cat[1], $desc, $cat[2]);
        $catStmt->execute();
    }
    $catStmt->close();

    // Default settings
    $settings = [
        ['ai_enabled', 'true'],
        ['exhibition_mode', 'false'],
        ['max_images_per_item', '8'],
        ['max_image_bytes', '5242880'],
        ['max_image_dimension', '4000'],
        ['interest_rate_limit_minutes', '5'],
        ['interest_rate_limit_count', '3'],
        ['public_base_path', '/wucportal/showcase'],
        ['currency_label', 'ZMW'],
        ['theme_year', '2026'],
        ['theme_title', 'Fostering Trade Investment'],
    ];
    $setStmt = $db->prepare("
        INSERT INTO enterprise_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    foreach ($settings as $s) {
        $setStmt->bind_param('ss', $s[0], $s[1]);
        $setStmt->execute();
    }
    $setStmt->close();

    // RBAC: module + permissions
    if (eh_table_exists($db, 'modules') && eh_table_exists($db, 'permissions')) {
        $modName = 'Skills-to-Trade Hub';
        $modKey = 'enterprise_hub';
        $modUrl = '/wucportal/admin/enterprise/index.php';
        $modIcon = 'fa-handshake';
        $modOrder = 45;
        $mStmt = $db->prepare("
            INSERT INTO modules (module_name, module_key, module_url, module_icon, display_order)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE module_name = VALUES(module_name), module_url = VALUES(module_url), module_icon = VALUES(module_icon)
        ");
        $mStmt->bind_param('ssssi', $modName, $modKey, $modUrl, $modIcon, $modOrder);
        $mStmt->execute();
        $mStmt->close();

        $moduleId = 0;
        $mGet = $db->prepare("SELECT module_id FROM modules WHERE module_key = ? LIMIT 1");
        $mGet->bind_param('s', $modKey);
        $mGet->execute();
        $mRow = $mGet->get_result()->fetch_assoc();
        $mGet->close();
        $moduleId = (int)($mRow['module_id'] ?? 0);

        $permDefs = [
            ['enterprise.profile.manage_own', 'Manage Own Enterprise Profile', 'Create and edit own enterprise profile'],
            ['enterprise.item.create', 'Create Enterprise Item', 'Create products, services and ideas'],
            ['enterprise.item.edit_own', 'Edit Own Enterprise Item', 'Edit own enterprise opportunities'],
            ['enterprise.item.submit', 'Submit Enterprise Item', 'Submit items for verification'],
            ['enterprise.item.view_own_interests', 'View Own Interests', 'View interest received on own items'],
            ['enterprise.review.lecturer', 'Lecturer Enterprise Review', 'Access lecturer review queue'],
            ['enterprise.review.request_changes', 'Request Enterprise Changes', 'Request changes on submitted items'],
            ['enterprise.review.verify', 'Verify Enterprise Item', 'Verify technical credibility'],
            ['enterprise.review.reject', 'Reject Enterprise Item', 'Reject submitted items'],
            ['enterprise.approve', 'Approve Enterprise Item', 'Approve verified items'],
            ['enterprise.publish', 'Publish Enterprise Item', 'Publish approved items'],
            ['enterprise.unpublish', 'Unpublish Enterprise Item', 'Unpublish published items'],
            ['enterprise.interests.manage', 'Manage Enterprise Interests', 'Manage expressions of interest'],
            ['enterprise.reports.view', 'View Enterprise Reports', 'View hub reports'],
            ['enterprise.settings.manage', 'Manage Enterprise Settings', 'Configure hub settings'],
            ['enterprise_hub.view', 'View Enterprise Hub', 'View hub dashboards'],
            ['enterprise_hub.manage', 'Manage Enterprise Hub', 'Full hub administration'],
        ];

        $pStmt = $db->prepare("
            INSERT INTO permissions (permission_key, permission_label, description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE permission_label = VALUES(permission_label), description = VALUES(description)
        ");
        foreach ($permDefs as $p) {
            $pStmt->bind_param('sss', $p[0], $p[1], $p[2]);
            $pStmt->execute();
        }
        $pStmt->close();

        if ($moduleId > 0 && eh_table_exists($db, 'role_permissions') && eh_table_exists($db, 'roles')) {
            $roleGrants = [
                'systems_admin' => array_column($permDefs, 0),
                'lecturer' => [
                    'enterprise.review.lecturer',
                    'enterprise.review.request_changes',
                    'enterprise.review.verify',
                    'enterprise.review.reject',
                    'enterprise_hub.view',
                ],
                'head_of_department' => [
                    'enterprise.review.lecturer',
                    'enterprise.review.request_changes',
                    'enterprise.review.verify',
                    'enterprise.review.reject',
                    'enterprise.approve',
                    'enterprise.publish',
                    'enterprise.unpublish',
                    'enterprise.interests.manage',
                    'enterprise.reports.view',
                    'enterprise_hub.view',
                    'enterprise_hub.manage',
                ],
                'registrar' => [
                    'enterprise.approve',
                    'enterprise.publish',
                    'enterprise.unpublish',
                    'enterprise.interests.manage',
                    'enterprise.reports.view',
                    'enterprise.settings.manage',
                    'enterprise_hub.view',
                    'enterprise_hub.manage',
                ],
            ];

            foreach ($roleGrants as $roleName => $keys) {
                $rStmt = $db->prepare("SELECT role_id FROM roles WHERE role_name = ? LIMIT 1");
                $rStmt->bind_param('s', $roleName);
                $rStmt->execute();
                $rRow = $rStmt->get_result()->fetch_assoc();
                $rStmt->close();
                if (!$rRow) {
                    continue;
                }
                $roleId = (int)$rRow['role_id'];
                foreach ($keys as $pk) {
                    $pidStmt = $db->prepare("SELECT permission_id FROM permissions WHERE permission_key = ? LIMIT 1");
                    $pidStmt->bind_param('s', $pk);
                    $pidStmt->execute();
                    $pidRow = $pidStmt->get_result()->fetch_assoc();
                    $pidStmt->close();
                    if (!$pidRow) {
                        continue;
                    }
                    $permissionId = (int)$pidRow['permission_id'];
                    $ins = $db->prepare("
                        INSERT INTO role_permissions (role_id, module_id, permission_id)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE status = 'active'
                    ");
                    $ins->bind_param('iii', $roleId, $moduleId, $permissionId);
                    $ins->execute();
                    $ins->close();
                }
            }
        }
    }

    $db->commit();
    echo "enterprise_hub migration completed successfully.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
