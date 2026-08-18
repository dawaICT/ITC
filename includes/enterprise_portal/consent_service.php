<?php
declare(strict_types=1);

if (!function_exists('ep_required_consent_types')) {
    /** @return list<string> */
    function ep_required_consent_types(): array
    {
        return [
            'participation_terms',
            'privacy_notice',
            'public_publication_rules',
            'content_accuracy',
            'contact_sharing',
            'financial_disclaimer',
            'code_of_conduct',
        ];
    }
}

if (!function_exists('ep_consent_labels')) {
    /** @return array<string,string> */
    function ep_consent_labels(): array
    {
        return [
            'participation_terms' => 'I accept the Skills and Enterprise Portal participation terms.',
            'privacy_notice' => 'I have read the privacy notice and understand how my information will be used.',
            'public_publication_rules' => 'I understand that only approved information may appear in the public directory.',
            'content_accuracy' => 'I declare that the information I provide will be accurate to the best of my knowledge.',
            'contact_sharing' => 'I understand that public contact is shared only according to my preferences and portal mediation settings.',
            'financial_disclaimer' => 'I understand that financial projections are estimates and not guaranteed outcomes.',
            'code_of_conduct' => 'I agree to follow the portal code of conduct.',
        ];
    }
}

if (!function_exists('ep_store_consents')) {
    /** @param array<string,bool> $consents */
    function ep_store_consents(mysqli $db, int $membershipId, int $userId, array $consents, string $version, string $ip = '', string $ua = ''): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO enterprise_consents
            (membership_id, user_id, consent_type, consent_version, is_accepted, accepted_at, ip_address, user_agent)
            VALUES (?,?,?,?,1,?,?,?)");
        foreach (ep_required_consent_types() as $type) {
            if (empty($consents[$type])) {
                continue;
            }
            $stmt->bind_param('iisssss', $membershipId, $userId, $type, $version, $now, $ip, $ua);
            $stmt->execute();
        }
        $stmt->close();
    }
}
