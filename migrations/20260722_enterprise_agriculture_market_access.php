<?php
declare(strict_types=1);

/**
 * Agriculture & Market Access — additive schema for Skills and Enterprise Portal.
 * Reversible: drop only the new tables listed in ep_agri_rollback() (manual).
 * Does not rename or drop existing enterprise_* tables.
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function ep_agri_exec(mysqli $db, string $sql): void
{
    if (!$db->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $db->error . "\n" . $sql);
    }
}

function ep_agri_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    $ok = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $ok;
}

function ep_agri_seed_permission(mysqli $db, string $key, string $label, string $desc): void
{
    $stmt = $db->prepare('INSERT INTO permissions (permission_key, permission_label, description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_label=VALUES(permission_label)');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sss', $key, $label, $desc);
    $stmt->execute();
    $stmt->close();
}

$db->begin_transaction();
try {
    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_commodities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            commodity_code VARCHAR(40) NOT NULL,
            commodity_name VARCHAR(120) NOT NULL,
            category_label VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_commodity_code (commodity_code),
            KEY idx_ep_commodity_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_commodity_varieties (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            commodity_id BIGINT UNSIGNED NOT NULL,
            variety_code VARCHAR(40) NOT NULL,
            variety_name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_variety (commodity_id, variety_code),
            CONSTRAINT fk_ep_variety_commodity FOREIGN KEY (commodity_id) REFERENCES enterprise_commodities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_measurement_units (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            unit_code VARCHAR(20) NOT NULL,
            unit_label VARCHAR(80) NOT NULL,
            base_unit_code VARCHAR(20) NOT NULL DEFAULT 'kg',
            to_base_factor DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_unit_code (unit_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_communication_channels (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            farmer_profile_id BIGINT UNSIGNED NULL,
            channel_type VARCHAR(20) NOT NULL,
            phone_e164 VARCHAR(20) NULL,
            preferred_language VARCHAR(20) NOT NULL DEFAULT 'en',
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_chan_user (user_id),
            KEY idx_ep_chan_phone (phone_e164),
            KEY idx_ep_chan_farmer (farmer_profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_farmer_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            farmer_code VARCHAR(32) NOT NULL,
            user_id INT NULL,
            membership_id BIGINT UNSIGNED NULL,
            enterprise_profile_id BIGINT UNSIGNED NULL,
            full_name VARCHAR(160) NOT NULL,
            province VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            camp_or_village VARCHAR(120) NULL,
            cooperative_name VARCHAR(160) NULL,
            preferred_language VARCHAR(20) NOT NULL DEFAULT 'en',
            preferred_channel VARCHAR(20) NOT NULL DEFAULT 'sms',
            verification_level VARCHAR(40) NOT NULL DEFAULT 'unverified',
            consent_status VARCHAR(40) NOT NULL DEFAULT 'pending',
            assigned_agent_user_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_by VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_farmer_code (farmer_code),
            KEY idx_ep_farmer_user (user_id),
            KEY idx_ep_farmer_agent (assigned_agent_user_id),
            KEY idx_ep_farmer_status (status),
            KEY idx_ep_farmer_district (province, district)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_farmer_farms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            farmer_profile_id BIGINT UNSIGNED NOT NULL,
            farm_name VARCHAR(120) NULL,
            location_description VARCHAR(255) NULL,
            farm_size DECIMAL(12,3) NULL,
            farm_size_unit VARCHAR(20) NULL,
            ownership_type VARCHAR(40) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_farm_farmer (farmer_profile_id),
            CONSTRAINT fk_ep_farm_farmer FOREIGN KEY (farmer_profile_id) REFERENCES enterprise_farmer_profiles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_farmer_crops (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            farmer_profile_id BIGINT UNSIGNED NOT NULL,
            commodity_id BIGINT UNSIGNED NOT NULL,
            variety_id BIGINT UNSIGNED NULL,
            estimated_quantity DECIMAL(14,3) NULL,
            quantity_unit VARCHAR(20) NULL,
            expected_harvest_date DATE NULL,
            season VARCHAR(40) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_fcrop_farmer (farmer_profile_id),
            KEY idx_ep_fcrop_commodity (commodity_id),
            CONSTRAINT fk_ep_fcrop_farmer FOREIGN KEY (farmer_profile_id) REFERENCES enterprise_farmer_profiles(id),
            CONSTRAINT fk_ep_fcrop_commodity FOREIGN KEY (commodity_id) REFERENCES enterprise_commodities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_produce_listings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            listing_code VARCHAR(32) NOT NULL,
            farmer_profile_id BIGINT UNSIGNED NOT NULL,
            opportunity_id BIGINT UNSIGNED NULL,
            commodity_id BIGINT UNSIGNED NOT NULL,
            variety_id BIGINT UNSIGNED NULL,
            grade_label VARCHAR(40) NULL,
            quantity DECIMAL(14,3) NOT NULL,
            quantity_unit VARCHAR(20) NOT NULL,
            quantity_base DECIMAL(18,6) NOT NULL,
            base_unit_code VARCHAR(20) NOT NULL DEFAULT 'kg',
            location_label VARCHAR(160) NOT NULL,
            province VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            availability_date DATE NULL,
            availability_status VARCHAR(40) NOT NULL DEFAULT 'available',
            asking_price DECIMAL(14,2) NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'ZMW',
            price_preference VARCHAR(40) NOT NULL DEFAULT 'negotiable',
            collection_preference VARCHAR(40) NOT NULL DEFAULT 'buyer_collection',
            verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
            requires_qty_reconfirm TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            created_by VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_produce_code (listing_code),
            KEY idx_ep_produce_farmer (farmer_profile_id),
            KEY idx_ep_produce_commodity (commodity_id),
            KEY idx_ep_produce_status (status, availability_status, expires_at),
            KEY idx_ep_produce_active (status, expires_at),
            CONSTRAINT fk_ep_produce_farmer FOREIGN KEY (farmer_profile_id) REFERENCES enterprise_farmer_profiles(id),
            CONSTRAINT fk_ep_produce_commodity FOREIGN KEY (commodity_id) REFERENCES enterprise_commodities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_buyer_crop_demands (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            demand_code VARCHAR(32) NOT NULL,
            buyer_user_id INT NULL,
            buyer_membership_id BIGINT UNSIGNED NULL,
            opportunity_id BIGINT UNSIGNED NULL,
            commodity_id BIGINT UNSIGNED NOT NULL,
            variety_id BIGINT UNSIGNED NULL,
            grade_label VARCHAR(40) NULL,
            quantity_required DECIMAL(14,3) NOT NULL,
            quantity_unit VARCHAR(20) NOT NULL,
            quantity_base DECIMAL(18,6) NOT NULL,
            base_unit_code VARCHAR(20) NOT NULL DEFAULT 'kg',
            location_label VARCHAR(160) NOT NULL,
            province VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            collection_or_delivery VARCHAR(40) NOT NULL DEFAULT 'buyer_collection',
            delivery_deadline DATE NULL,
            offered_price DECIMAL(14,2) NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'ZMW',
            pricing_method VARCHAR(40) NOT NULL DEFAULT 'quote_on_request',
            payment_terms VARCHAR(255) NULL,
            buyer_verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
            expires_at DATETIME NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            review_notes TEXT NULL,
            created_by VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_demand_code (demand_code),
            KEY idx_ep_demand_buyer (buyer_user_id),
            KEY idx_ep_demand_commodity (commodity_id),
            KEY idx_ep_demand_status (status, expires_at),
            CONSTRAINT fk_ep_demand_commodity FOREIGN KEY (commodity_id) REFERENCES enterprise_commodities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_produce_matches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            demand_id BIGINT UNSIGNED NOT NULL,
            listing_id BIGINT UNSIGNED NOT NULL,
            match_score DECIMAL(8,2) NULL,
            match_explanation VARCHAR(500) NULL,
            quantity_proposed DECIMAL(14,3) NOT NULL,
            quantity_unit VARCHAR(20) NOT NULL,
            officer_user_id INT NULL,
            officer_decision VARCHAR(40) NOT NULL DEFAULT 'suggested',
            farmer_consent_status VARCHAR(40) NOT NULL DEFAULT 'pending',
            farmer_consent_channel VARCHAR(40) NULL,
            farmer_consent_at DATETIME NULL,
            buyer_ack_status VARCHAR(40) NOT NULL DEFAULT 'pending',
            outcome_status VARCHAR(40) NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_pm_demand (demand_id),
            KEY idx_ep_pm_listing (listing_id),
            KEY idx_ep_pm_consent (farmer_consent_status),
            CONSTRAINT fk_ep_pm_demand FOREIGN KEY (demand_id) REFERENCES enterprise_buyer_crop_demands(id),
            CONSTRAINT fk_ep_pm_listing FOREIGN KEY (listing_id) REFERENCES enterprise_produce_listings(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_commodity_price_sources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            source_code VARCHAR(40) NOT NULL,
            source_name VARCHAR(160) NOT NULL,
            source_type VARCHAR(40) NOT NULL,
            contact_details VARCHAR(255) NULL,
            verification_method VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_price_source (source_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_commodity_prices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            commodity_id BIGINT UNSIGNED NOT NULL,
            variety_id BIGINT UNSIGNED NULL,
            grade_label VARCHAR(40) NULL,
            price DECIMAL(14,2) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'ZMW',
            quantity_unit VARCHAR(20) NOT NULL,
            location_label VARCHAR(160) NOT NULL,
            province VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            price_type VARCHAR(40) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            source_reference VARCHAR(255) NULL,
            document_path VARCHAR(255) NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            version_no INT NOT NULL DEFAULT 1,
            supersedes_price_id BIGINT UNSIGNED NULL,
            created_by VARCHAR(80) NOT NULL,
            verified_by VARCHAR(80) NULL,
            verified_at DATETIME NULL,
            second_approved_by VARCHAR(80) NULL,
            second_approved_at DATETIME NULL,
            publication_status VARCHAR(40) NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_price_commodity (commodity_id),
            KEY idx_ep_price_type (price_type),
            KEY idx_ep_price_pub (publication_status, effective_from, effective_to),
            KEY idx_ep_price_loc (province, district),
            CONSTRAINT fk_ep_price_commodity FOREIGN KEY (commodity_id) REFERENCES enterprise_commodities(id),
            CONSTRAINT fk_ep_price_source FOREIGN KEY (source_id) REFERENCES enterprise_commodity_price_sources(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_sms_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            farmer_profile_id BIGINT UNSIGNED NULL,
            phone_number VARCHAR(20) NOT NULL,
            direction VARCHAR(10) NOT NULL,
            message_type VARCHAR(40) NOT NULL,
            message_text TEXT NOT NULL,
            provider_name VARCHAR(40) NULL,
            provider_reference VARCHAR(80) NULL,
            delivery_status VARCHAR(40) NOT NULL DEFAULT 'queued',
            sent_at DATETIME NULL,
            received_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_sms_provider_ref (provider_reference),
            KEY idx_ep_sms_phone (phone_number),
            KEY idx_ep_sms_status (delivery_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_agri_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_ussd_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_reference VARCHAR(80) NOT NULL,
            phone_e164 VARCHAR(20) NOT NULL,
            farmer_profile_id BIGINT UNSIGNED NULL,
            user_id INT NULL,
            current_step VARCHAR(40) NOT NULL DEFAULT 'menu',
            session_state_json JSON NULL,
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            completion_status VARCHAR(40) NOT NULL DEFAULT 'open',
            provider_request_id VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_ussd_session (session_reference),
            UNIQUE KEY uq_ep_ussd_provider_req (provider_request_id),
            KEY idx_ep_ussd_phone (phone_e164),
            KEY idx_ep_ussd_exp (expires_at, completion_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Settings
    if (ep_agri_table_exists($db, 'enterprise_portal_settings')) {
        $settings = [
            ['agriculture_enabled', 'true'],
            ['agriculture_market_access_nav', 'true'],
            ['ussd_enabled', 'false'],
            ['sms_enabled', 'true'],
            ['official_price_approval_mode', 'dual'],
        ];
        $stmt = $db->prepare('INSERT INTO enterprise_portal_settings (setting_key, setting_value) VALUES (?,?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        if ($stmt) {
            foreach ($settings as [$k, $v]) {
                $stmt->bind_param('ss', $k, $v);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    // Seed commodities / units
    $commodities = [
        ['MAIZE', 'Maize', 'Cereals'],
        ['SOYA', 'Soya beans', 'Oilseeds'],
        ['GROUNDNUT', 'Groundnuts', 'Oilseeds'],
        ['CASSAVA', 'Cassava', 'Roots'],
    ];
    $cstmt = $db->prepare('INSERT IGNORE INTO enterprise_commodities (commodity_code, commodity_name, category_label) VALUES (?,?,?)');
    if ($cstmt) {
        foreach ($commodities as [$code, $name, $cat]) {
            $cstmt->bind_param('sss', $code, $name, $cat);
            $cstmt->execute();
        }
        $cstmt->close();
    }

    $units = [
        ['kg', 'Kilogram', 'kg', '1'],
        ['bag50', '50 kg bag', 'kg', '50'],
        ['tonne', 'Metric tonne', 'kg', '1000'],
    ];
    $ustmt = $db->prepare('INSERT IGNORE INTO enterprise_measurement_units (unit_code, unit_label, base_unit_code, to_base_factor) VALUES (?,?,?,?)');
    if ($ustmt) {
        foreach ($units as [$uc, $ul, $bu, $f]) {
            $ustmt->bind_param('ssss', $uc, $ul, $bu, $f);
            $ustmt->execute();
        }
        $ustmt->close();
    }

    $sources = [
        ['MANUAL_OFFICER', 'Institution price officer', 'institutional'],
        ['BUYER_OFFER', 'Verified buyer offer desk', 'buyer'],
        ['LOCAL_MARKET', 'Local market reference', 'market'],
    ];
    $sstmt = $db->prepare('INSERT IGNORE INTO enterprise_commodity_price_sources (source_code, source_name, source_type, verification_method) VALUES (?,?,?,?)');
    if ($sstmt) {
        $method = 'document_review';
        foreach ($sources as [$sc, $sn, $st]) {
            $sstmt->bind_param('ssss', $sc, $sn, $st, $method);
            $sstmt->execute();
        }
        $sstmt->close();
    }

    if (ep_agri_table_exists($db, 'permissions')) {
        $perms = [
            ['agriculture.farmers.create', 'Create farmers', 'Register farmers (agent/officer)'],
            ['agriculture.farmers.manage_assigned', 'Manage assigned farmers', 'Update assigned farmer records'],
            ['agriculture.farmers.verify', 'Verify farmers', 'Set farmer verification levels'],
            ['agriculture.cooperatives.manage', 'Manage cooperatives', 'Cooperative administration'],
            ['agriculture.produce.create_own', 'Create produce listings', 'Create own produce listings'],
            ['agriculture.produce.manage_assigned', 'Manage assigned produce', 'Manage produce for assigned farmers'],
            ['agriculture.produce.verify', 'Verify produce', 'Verify produce listings'],
            ['agriculture.buyers.manage', 'Manage crop buyers', 'Verify and manage crop buyers'],
            ['agriculture.demands.create', 'Create crop demands', 'Submit buyer crop demands'],
            ['agriculture.demands.review', 'Review crop demands', 'Review buyer crop demands'],
            ['agriculture.matches.create', 'Create produce matches', 'Suggest farmer–buyer matches'],
            ['agriculture.matches.review', 'Review produce matches', 'Approve or reject matches'],
            ['agriculture.referrals.manage', 'Manage agriculture referrals', 'Manage consented introductions'],
            ['agriculture.prices.create', 'Create commodity prices', 'Draft price records'],
            ['agriculture.prices.verify', 'Verify commodity prices', 'Verify price sources'],
            ['agriculture.prices.publish', 'Publish commodity prices', 'Publish verified prices'],
            ['agriculture.prices.correct', 'Correct commodity prices', 'Create price corrections'],
            ['agriculture.sms.manage', 'Manage agriculture SMS', 'SMS templates and queues'],
            ['agriculture.ussd.manage', 'Manage agriculture USSD', 'USSD configuration'],
            ['agriculture.reports.view', 'View agriculture reports', 'Market access reports'],
        ];
        foreach ($perms as [$k, $l, $d]) {
            ep_agri_seed_permission($db, $k, $l, $d);
        }

        // Grant agriculture management set to registrar / HOD / dean / systems_admin if roles exist
        $roleNames = ['systems_admin', 'registrar', 'head_of_department', 'dean'];
        foreach ($roleNames as $roleName) {
            $role = $db->prepare('SELECT role_id FROM roles WHERE role_name = ? LIMIT 1');
            if (!$role) {
                continue;
            }
            $role->bind_param('s', $roleName);
            $role->execute();
            $rr = $role->get_result()->fetch_assoc();
            $role->close();
            if (!$rr) {
                continue;
            }
            $roleId = (int)$rr['role_id'];
            $link = $db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT ?, permission_id FROM permissions WHERE permission_key LIKE 'agriculture.%'");
            if ($link) {
                $link->bind_param('i', $roleId);
                $link->execute();
                $link->close();
            }
        }
    }

    $db->commit();
    echo "OK: Agriculture market access schema migrated.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
