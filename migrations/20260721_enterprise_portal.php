<?php
declare(strict_types=1);

/**
 * Skills and Enterprise Portal — schema + portal registration.
 * Additive only. Does not drop legacy exhibition hub tables (enterprise_items, etc.).
 */

require_once dirname(__DIR__) . '/db/connect.php';

if (!isset($db) || !($db instanceof mysqli)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

function ep_exec(mysqli $db, string $sql): void
{
    if (!$db->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $db->error . "\n" . $sql);
    }
}

function ep_table_exists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    $ok = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }
    return $ok;
}

$db->begin_transaction();
try {
    // Register portal
    if (ep_table_exists($db, 'portals')) {
        $code = 'enterprise';
        $name = 'Skills and Enterprise Portal';
        $desc = 'Build a professional profile, showcase skills, products, services and innovations, and connect with employment, business and partnership opportunities.';
        $status = 'active';
        $stmt = $db->prepare("
            INSERT INTO portals (portal_code, portal_name, description, status)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE portal_name = VALUES(portal_name), description = VALUES(description), status = VALUES(status)
        ");
        // portals may not have unique on portal_code — check then insert
        $chk = $db->prepare('SELECT id FROM portals WHERE portal_code = ? LIMIT 1');
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($exists) {
            $upd = $db->prepare('UPDATE portals SET portal_name=?, description=?, status=? WHERE portal_code=?');
            $upd->bind_param('ssss', $name, $desc, $status, $code);
            $upd->execute();
            $upd->close();
        } else {
            $ins = $db->prepare('INSERT INTO portals (portal_code, portal_name, description, status) VALUES (?,?,?,?)');
            $ins->bind_param('ssss', $code, $name, $desc, $status);
            $ins->execute();
            $ins->close();
        }
        if ($stmt) {
            $stmt->close();
        }
    }

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_memberships (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            student_id VARCHAR(50) NULL,
            graduate_id VARCHAR(50) NULL,
            membership_type VARCHAR(40) NOT NULL DEFAULT 'student',
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            participation_goals_json JSON NULL,
            eligibility_result VARCHAR(40) NULL,
            eligibility_notes VARCHAR(500) NULL,
            submitted_at DATETIME NULL,
            approved_at DATETIME NULL,
            approved_by VARCHAR(80) NULL,
            declined_at DATETIME NULL,
            declined_by VARCHAR(80) NULL,
            decline_reason TEXT NULL,
            suspended_at DATETIME NULL,
            suspended_by VARCHAR(80) NULL,
            suspension_reason TEXT NULL,
            withdrawn_at DATETIME NULL,
            withdrawal_reason TEXT NULL,
            last_status_change_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            KEY idx_ep_mem_user (user_id),
            KEY idx_ep_mem_student (student_id),
            KEY idx_ep_mem_status (status),
            KEY idx_ep_mem_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_consents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            membership_id BIGINT UNSIGNED NOT NULL,
            user_id INT NOT NULL,
            consent_type VARCHAR(80) NOT NULL,
            consent_version VARCHAR(40) NOT NULL,
            is_accepted TINYINT(1) NOT NULL DEFAULT 0,
            accepted_at DATETIME NULL,
            withdrawn_at DATETIME NULL,
            ip_address VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_consent_membership (membership_id),
            KEY idx_ep_consent_user (user_id),
            KEY idx_ep_consent_type (consent_type),
            CONSTRAINT fk_ep_consent_membership FOREIGN KEY (membership_id) REFERENCES enterprise_memberships(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_member_profiles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            membership_id BIGINT UNSIGNED NOT NULL,
            owner_user_id INT NOT NULL,
            profile_type VARCHAR(40) NOT NULL DEFAULT 'professional',
            business_name VARCHAR(180) NULL,
            professional_title VARCHAR(180) NULL,
            short_bio TEXT NULL,
            full_description MEDIUMTEXT NULL,
            province VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            preferred_contact_method VARCHAR(40) NOT NULL DEFAULT 'portal_mediated',
            public_phone VARCHAR(40) NULL,
            public_email VARCHAR(120) NULL,
            business_registration_status VARCHAR(40) NULL,
            registration_number VARCHAR(80) NULL,
            years_operating INT NULL,
            programme_code VARCHAR(50) NULL,
            programme_name VARCHAR(180) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            UNIQUE KEY uq_ep_profile_membership (membership_id),
            KEY idx_ep_profile_owner (owner_user_id),
            CONSTRAINT fk_ep_profile_membership FOREIGN KEY (membership_id) REFERENCES enterprise_memberships(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_skills (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_profile_id BIGINT UNSIGNED NOT NULL,
            skill_name VARCHAR(160) NOT NULL,
            skill_category VARCHAR(80) NULL,
            proficiency_level VARCHAR(40) NOT NULL DEFAULT 'intermediate',
            years_experience DECIMAL(4,1) NULL,
            evidence_description TEXT NULL,
            verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
            verified_by VARCHAR(80) NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_skill_profile (enterprise_profile_id),
            CONSTRAINT fk_ep_skill_profile FOREIGN KEY (enterprise_profile_id) REFERENCES enterprise_member_profiles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Categories: reuse enterprise_categories if present; ensure table exists
    if (!ep_table_exists($db, 'enterprise_categories')) {
        ep_exec($db, "
            CREATE TABLE enterprise_categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                category_name VARCHAR(120) NOT NULL,
                category_slug VARCHAR(140) NOT NULL,
                description VARCHAR(500) NULL,
                department_id INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ep_cat_slug (category_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_opportunities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_profile_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            opportunity_type VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            slug VARCHAR(220) NOT NULL,
            short_description VARCHAR(500) NULL,
            full_description MEDIUMTEXT NULL,
            pricing_type VARCHAR(40) NULL,
            unit_price DECIMAL(14,2) NULL,
            minimum_price DECIMAL(14,2) NULL,
            maximum_price DECIMAL(14,2) NULL,
            currency CHAR(3) NOT NULL DEFAULT 'ZMW',
            service_area VARCHAR(200) NULL,
            availability_status VARCHAR(40) NOT NULL DEFAULT 'available',
            current_capacity INT NULL,
            capacity_period VARCHAR(40) NULL,
            innovation_stage VARCHAR(40) NULL,
            prototype_status VARCHAR(80) NULL,
            problem_statement TEXT NULL,
            proposed_solution TEXT NULL,
            investment_required DECIMAL(14,2) NULL,
            investment_purpose VARCHAR(500) NULL,
            expected_capacity INT NULL,
            expected_capacity_period VARCHAR(40) NULL,
            employment_potential INT NULL,
            preferred_employment_type VARCHAR(80) NULL,
            preferred_location VARCHAR(120) NULL,
            public_code VARCHAR(32) NOT NULL,
            readiness_score DECIMAL(5,2) NULL,
            readiness_level VARCHAR(60) NULL,
            cost_visibility VARCHAR(40) NOT NULL DEFAULT 'private',
            view_count INT UNSIGNED NOT NULL DEFAULT 0,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'draft',
            submitted_at DATETIME NULL,
            reviewer_verified_at DATETIME NULL,
            approved_at DATETIME NULL,
            published_at DATETIME NULL,
            unpublished_at DATETIME NULL,
            last_verified_at DATETIME NULL,
            next_review_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            UNIQUE KEY uq_ep_opp_public_code (public_code),
            UNIQUE KEY uq_ep_opp_slug (slug),
            KEY idx_ep_opp_profile (enterprise_profile_id),
            KEY idx_ep_opp_status (status),
            KEY idx_ep_opp_type (opportunity_type),
            KEY idx_ep_opp_category (category_id),
            KEY idx_ep_opp_published (status, published_at),
            CONSTRAINT fk_ep_opp_profile FOREIGN KEY (enterprise_profile_id) REFERENCES enterprise_member_profiles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_opportunity_id BIGINT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            media_type VARCHAR(40) NOT NULL DEFAULT 'image',
            caption VARCHAR(255) NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            file_hash VARCHAR(64) NULL,
            uploaded_by VARCHAR(80) NOT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ep_media_opp (enterprise_opportunity_id),
            CONSTRAINT fk_ep_media_opp FOREIGN KEY (enterprise_opportunity_id) REFERENCES enterprise_opportunities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_opportunity_costs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_opportunity_id BIGINT UNSIGNED NOT NULL,
            material_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            labour_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            transport_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            utilities_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            packaging_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            marketing_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            other_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            number_of_units INT NOT NULL DEFAULT 1,
            total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            cost_per_unit DECIMAL(14,2) NOT NULL DEFAULT 0,
            selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
            profit_per_unit DECIMAL(14,2) NOT NULL DEFAULT 0,
            expected_revenue DECIMAL(14,2) NOT NULL DEFAULT 0,
            expected_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
            profit_margin DECIMAL(8,2) NOT NULL DEFAULT 0,
            break_even_quantity INT NULL,
            calculation_version INT NOT NULL DEFAULT 1,
            calculated_at DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_cost_opp (enterprise_opportunity_id),
            CONSTRAINT fk_ep_cost_opp FOREIGN KEY (enterprise_opportunity_id) REFERENCES enterprise_opportunities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_opportunity_readiness (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_opportunity_id BIGINT UNSIGNED NOT NULL,
            product_score INT NOT NULL,
            market_score INT NOT NULL,
            costing_score INT NOT NULL,
            capacity_score INT NOT NULL,
            compliance_score INT NOT NULL,
            team_score INT NOT NULL,
            total_score DECIMAL(5,2) NOT NULL,
            readiness_level VARCHAR(60) NOT NULL,
            recommendations_json JSON NULL,
            completed_by VARCHAR(80) NOT NULL,
            assessed_at DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_ready_opp (enterprise_opportunity_id),
            CONSTRAINT fk_ep_ready_opp FOREIGN KEY (enterprise_opportunity_id) REFERENCES enterprise_opportunities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_opportunity_reviews (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_opportunity_id BIGINT UNSIGNED NOT NULL,
            reviewer_id VARCHAR(80) NOT NULL,
            review_stage VARCHAR(40) NOT NULL,
            decision VARCHAR(40) NOT NULL,
            comments TEXT NULL,
            previous_status VARCHAR(40) NULL,
            resulting_status VARCHAR(40) NULL,
            assigned_at DATETIME NULL,
            review_deadline DATETIME NULL,
            reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ep_rev_opp (enterprise_opportunity_id),
            KEY idx_ep_rev_reviewer (reviewer_id),
            CONSTRAINT fk_ep_rev_opp FOREIGN KEY (enterprise_opportunity_id) REFERENCES enterprise_opportunities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_opportunity_interests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_opportunity_id BIGINT UNSIGNED NOT NULL,
            visitor_name VARCHAR(120) NOT NULL,
            organization VARCHAR(180) NULL,
            email VARCHAR(160) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            interest_type VARCHAR(60) NOT NULL,
            interest_details_json JSON NULL,
            investment_range VARCHAR(120) NULL,
            quantity_requested INT NULL,
            preferred_contact_method VARCHAR(40) NOT NULL DEFAULT 'email',
            message TEXT NOT NULL,
            consent_accepted TINYINT(1) NOT NULL DEFAULT 0,
            lead_status VARCHAR(40) NOT NULL DEFAULT 'new',
            assigned_to VARCHAR(80) NULL,
            internal_notes TEXT NULL,
            source VARCHAR(60) NOT NULL DEFAULT 'directory',
            ip_hash VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            closure_reason VARCHAR(255) NULL,
            KEY idx_ep_int_opp (enterprise_opportunity_id),
            KEY idx_ep_int_lead (lead_status),
            KEY idx_ep_int_assigned (assigned_to),
            CONSTRAINT fk_ep_int_opp FOREIGN KEY (enterprise_opportunity_id) REFERENCES enterprise_opportunities(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_outcomes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_interest_id BIGINT UNSIGNED NULL,
            enterprise_opportunity_id BIGINT UNSIGNED NULL,
            enterprise_profile_id BIGINT UNSIGNED NULL,
            outcome_type VARCHAR(60) NOT NULL,
            outcome_stage VARCHAR(60) NOT NULL DEFAULT 'recorded',
            estimated_value DECIMAL(14,2) NULL,
            currency CHAR(3) NULL DEFAULT 'ZMW',
            jobs_created INT NULL,
            description TEXT NULL,
            evidence_path VARCHAR(500) NULL,
            verification_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
            recorded_by VARCHAR(80) NOT NULL,
            verified_by VARCHAR(80) NULL,
            recorded_at DATETIME NOT NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ep_out_type (outcome_type),
            KEY idx_ep_out_interest (enterprise_interest_id),
            KEY idx_ep_out_opp (enterprise_opportunity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_complaints (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            enterprise_opportunity_id BIGINT UNSIGNED NULL,
            reporter_name VARCHAR(120) NULL,
            reporter_email VARCHAR(160) NULL,
            complaint_type VARCHAR(60) NOT NULL,
            description TEXT NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'submitted',
            assigned_to VARCHAR(80) NULL,
            resolution_notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            KEY idx_ep_comp_status (status),
            KEY idx_ep_comp_opp (enterprise_opportunity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_portal_settings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ep_setting (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    ep_exec($db, "
        CREATE TABLE IF NOT EXISTS enterprise_interest_throttle (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ip_hash VARCHAR(64) NOT NULL,
            email_hash VARCHAR(64) NOT NULL,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ep_throttle (ip_hash, email_hash, submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $defaults = [
        ['portal_enabled', 'true'],
        ['membership_approval_mode', 'manual'],
        ['allow_current_students', 'true'],
        ['allow_graduates', 'true'],
        ['public_directory', 'true'],
        ['ai_enabled', 'false'],
        ['monetization_enabled', 'false'],
        ['consent_version', '1.0'],
        ['lead_attention_days', '3'],
        ['lead_overdue_days', '6'],
        ['lead_escalated_days', '10'],
        ['currency_label', 'ZMW'],
    ];
    $set = $db->prepare('INSERT INTO enterprise_portal_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ($defaults as $d) {
        $set->bind_param('ss', $d[0], $d[1]);
        $set->execute();
    }
    $set->close();

    // Seed categories if empty
    $count = (int)($db->query('SELECT COUNT(*) c FROM enterprise_categories')->fetch_assoc()['c'] ?? 0);
    if ($count === 0) {
        $cats = [
            ['Agriculture and Agro-processing', 'agriculture-agro-processing', 10],
            ['Automotive', 'automotive', 20],
            ['Construction', 'construction', 30],
            ['ICT and Digital Services', 'ict-digital-services', 40],
            ['Fabrication and Welding', 'fabrication-welding', 50],
            ['Tailoring and Fashion', 'tailoring-fashion', 60],
            ['Food Production', 'food-production', 70],
            ['General Business Services', 'general-business-services', 80],
            ['Professional Skills', 'professional-skills', 90],
        ];
        $cstmt = $db->prepare('INSERT INTO enterprise_categories (category_name, category_slug, is_active, display_order) VALUES (?,?,1,?)');
        foreach ($cats as $c) {
            $cstmt->bind_param('ssi', $c[0], $c[1], $c[2]);
            $cstmt->execute();
        }
        $cstmt->close();
    }

    // Permissions
    if (ep_table_exists($db, 'permissions')) {
        $perms = [
            ['enterprise.portal.join', 'Join Enterprise Portal'],
            ['enterprise.portal.access', 'Access Enterprise Portal'],
            ['enterprise.portal.withdraw', 'Withdraw Enterprise Participation'],
            ['enterprise.profile.manage_own', 'Manage Own Enterprise Profile'],
            ['enterprise.skills.manage_own', 'Manage Own Skills'],
            ['enterprise.opportunity.create', 'Create Enterprise Opportunity'],
            ['enterprise.opportunity.edit_own', 'Edit Own Opportunity'],
            ['enterprise.opportunity.submit', 'Submit Opportunity'],
            ['enterprise.opportunity.archive_own', 'Archive Own Opportunity'],
            ['enterprise.interests.view_own', 'View Own Interests'],
            ['enterprise.review.access', 'Access Enterprise Reviews'],
            ['enterprise.review.request_changes', 'Request Opportunity Changes'],
            ['enterprise.review.verify', 'Verify Opportunity'],
            ['enterprise.review.reject', 'Reject Opportunity'],
            ['enterprise.review.reassign', 'Reassign Review'],
            ['enterprise.memberships.manage', 'Manage Memberships'],
            ['enterprise.approve', 'Approve Opportunities'],
            ['enterprise.publish', 'Publish Opportunities'],
            ['enterprise.unpublish', 'Unpublish Opportunities'],
            ['enterprise.feature', 'Feature Opportunities'],
            ['enterprise.interests.manage', 'Manage Interests'],
            ['enterprise.leads.assign', 'Assign Leads'],
            ['enterprise.outcomes.manage', 'Manage Outcomes'],
            ['enterprise.reports.view', 'View Enterprise Reports'],
            ['enterprise.settings.manage', 'Manage Enterprise Settings'],
            ['enterprise.audit.view', 'View Enterprise Audit'],
        ];
        $p = $db->prepare('INSERT INTO permissions (permission_key, permission_label, description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission_label=VALUES(permission_label)');
        foreach ($perms as $row) {
            $desc = $row[1];
            $p->bind_param('sss', $row[0], $row[1], $desc);
            $p->execute();
        }
        $p->close();
    }

    $db->commit();
    echo "enterprise_portal migration completed successfully.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
