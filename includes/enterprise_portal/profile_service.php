<?php
declare(strict_types=1);

if (!function_exists('ep_get_profile_by_membership')) {
    function ep_get_profile_by_membership(mysqli $db, int $membershipId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_member_profiles WHERE membership_id = ? LIMIT 1');
        $stmt->bind_param('i', $membershipId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('ep_save_member_profile')) {
    /** @param array<string,mixed> $data */
    function ep_save_member_profile(mysqli $db, int $membershipId, array $data): int
    {
        $existing = ep_get_profile_by_membership($db, $membershipId);
        $owner = ep_current_user_id();
        $fields = [
            'profile_type' => (string)($data['profile_type'] ?? 'professional'),
            'business_name' => trim((string)($data['business_name'] ?? '')),
            'professional_title' => trim((string)($data['professional_title'] ?? '')),
            'short_bio' => trim((string)($data['short_bio'] ?? '')),
            'full_description' => trim((string)($data['full_description'] ?? '')),
            'province' => trim((string)($data['province'] ?? '')),
            'district' => trim((string)($data['district'] ?? '')),
            'preferred_contact_method' => trim((string)($data['preferred_contact_method'] ?? 'portal_mediated')),
            'public_phone' => trim((string)($data['public_phone'] ?? '')),
            'public_email' => trim((string)($data['public_email'] ?? '')),
            'business_registration_status' => trim((string)($data['business_registration_status'] ?? '')),
            'registration_number' => trim((string)($data['registration_number'] ?? '')),
            'programme_code' => trim((string)($data['programme_code'] ?? '')),
            'programme_name' => trim((string)($data['programme_name'] ?? '')),
        ];
        $years = isset($data['years_operating']) && $data['years_operating'] !== '' ? (int)$data['years_operating'] : null;

        if ($existing) {
            $id = (int)$existing['id'];
            $stmt = $db->prepare("UPDATE enterprise_member_profiles SET
                profile_type=?, business_name=?, professional_title=?, short_bio=?, full_description=?,
                province=?, district=?, preferred_contact_method=?, public_phone=?, public_email=?,
                business_registration_status=?, registration_number=?, years_operating=?,
                programme_code=?, programme_name=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param(
                'ssssssssssssissi',
                $fields['profile_type'], $fields['business_name'], $fields['professional_title'],
                $fields['short_bio'], $fields['full_description'], $fields['province'], $fields['district'],
                $fields['preferred_contact_method'], $fields['public_phone'], $fields['public_email'],
                $fields['business_registration_status'], $fields['registration_number'], $years,
                $fields['programme_code'], $fields['programme_name'], $id
            );
            $stmt->execute();
            $stmt->close();
            ep_audit($db, 'enterprise_portal.profile_updated', ['profile_id' => $id]);
            return $id;
        }

        $stmt = $db->prepare("INSERT INTO enterprise_member_profiles (
            membership_id, owner_user_id, profile_type, business_name, professional_title, short_bio, full_description,
            province, district, preferred_contact_method, public_phone, public_email,
            business_registration_status, registration_number, years_operating, programme_code, programme_name
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param(
            'iisssssssssssisss',
            $membershipId, $owner, $fields['profile_type'], $fields['business_name'], $fields['professional_title'],
            $fields['short_bio'], $fields['full_description'], $fields['province'], $fields['district'],
            $fields['preferred_contact_method'], $fields['public_phone'], $fields['public_email'],
            $fields['business_registration_status'], $fields['registration_number'], $years,
            $fields['programme_code'], $fields['programme_name']
        );
        $stmt->execute();
        $id = (int)$db->insert_id;
        $stmt->close();
        ep_audit($db, 'enterprise_portal.profile_created', ['profile_id' => $id]);
        return $id;
    }
}

if (!function_exists('ep_profile_completion')) {
    /** @return array{percent:int,missing:list<string>} */
    function ep_profile_completion(mysqli $db, array $membership, ?array $profile): array
    {
        $missing = [];
        $score = 0;
        $goals = json_decode((string)($membership['participation_goals_json'] ?? '[]'), true) ?: [];

        // Identity 15
        if ($profile && (trim((string)($profile['short_bio'] ?? '')) !== '' || trim((string)($profile['professional_title'] ?? '')) !== '' || trim((string)($profile['business_name'] ?? '')) !== '')) {
            $score += 15;
        } else {
            $missing[] = 'Complete your professional summary or enterprise name.';
        }
        // Goals 10
        if ($goals !== []) {
            $score += 10;
        } else {
            $missing[] = 'Select participation goals.';
        }
        // Skills 20
        $skillCount = 0;
        if ($profile) {
            $pid = (int)$profile['id'];
            $s = $db->prepare('SELECT COUNT(*) c FROM enterprise_skills WHERE enterprise_profile_id = ?');
            $s->bind_param('i', $pid);
            $s->execute();
            $skillCount = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
            $s->close();
        }
        if ($skillCount > 0) {
            $score += 20;
        } else {
            $missing[] = 'Add at least one skill.';
        }
        // Contact 10
        if ($profile && trim((string)($profile['preferred_contact_method'] ?? '')) !== '') {
            $score += 10;
        } else {
            $missing[] = 'Set preferred contact method.';
        }
        // Portfolio evidence (skills evidence) 15
        $ev = 0;
        if ($profile) {
            $pid = (int)$profile['id'];
            $s = $db->prepare("SELECT COUNT(*) c FROM enterprise_skills WHERE enterprise_profile_id = ? AND evidence_description IS NOT NULL AND evidence_description <> ''");
            $s->bind_param('i', $pid);
            $s->execute();
            $ev = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
            $s->close();
        }
        if ($ev > 0) {
            $score += 15;
        } else {
            $missing[] = 'Add evidence for at least one skill.';
        }
        // First opportunity 20
        $opp = 0;
        if ($profile) {
            $pid = (int)$profile['id'];
            $s = $db->prepare('SELECT COUNT(*) c FROM enterprise_opportunities WHERE enterprise_profile_id = ? AND status <> \'archived\'');
            $s->bind_param('i', $pid);
            $s->execute();
            $opp = (int)($s->get_result()->fetch_assoc()['c'] ?? 0);
            $s->close();
        }
        if ($opp > 0) {
            $score += 20;
        } else {
            $missing[] = 'Create your first opportunity.';
        }
        // Consent/public preview 10 — membership submitted implies consent
        if (in_array((string)$membership['status'], ['pending', 'active', 'changes_requested'], true)) {
            $score += 10;
        } else {
            $missing[] = 'Complete membership consent.';
        }

        return ['percent' => min(100, $score), 'missing' => $missing];
    }
}

if (!function_exists('ep_list_skills')) {
    function ep_list_skills(mysqli $db, int $profileId): array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_skills WHERE enterprise_profile_id = ? ORDER BY skill_name');
        $stmt->bind_param('i', $profileId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ep_save_skill')) {
    /** @param array<string,mixed> $data */
    function ep_save_skill(mysqli $db, int $profileId, array $data, ?int $id = null): int
    {
        $name = trim((string)($data['skill_name'] ?? ''));
        $cat = trim((string)($data['skill_category'] ?? ''));
        $level = trim((string)($data['proficiency_level'] ?? 'intermediate'));
        $years = isset($data['years_experience']) && $data['years_experience'] !== '' ? (float)$data['years_experience'] : null;
        $evidence = trim((string)($data['evidence_description'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Skill name is required.');
        }
        if ($id) {
            $stmt = $db->prepare('UPDATE enterprise_skills SET skill_name=?, skill_category=?, proficiency_level=?, years_experience=?, evidence_description=?, updated_at=NOW() WHERE id=? AND enterprise_profile_id=?');
            $stmt->bind_param('sssdssi', $name, $cat, $level, $years, $evidence, $id, $profileId);
            $stmt->execute();
            $stmt->close();
            return $id;
        }
        $stmt = $db->prepare('INSERT INTO enterprise_skills (enterprise_profile_id, skill_name, skill_category, proficiency_level, years_experience, evidence_description) VALUES (?,?,?,?,?,?)');
        $stmt->bind_param('isssds', $profileId, $name, $cat, $level, $years, $evidence);
        $stmt->execute();
        $newId = (int)$db->insert_id;
        $stmt->close();
        return $newId;
    }
}
