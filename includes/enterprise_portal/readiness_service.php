<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/enterprise_hub/readiness.php';

if (!function_exists('ep_calculate_readiness')) {
    /** @param array<string,mixed> $input */
    function ep_calculate_readiness(array $input): array
    {
        return eh_calculate_readiness($input);
    }
}

if (!function_exists('ep_save_readiness')) {
    /** @param array<string,mixed> $input */
    function ep_save_readiness(mysqli $db, int $opportunityId, array $input): array
    {
        $calc = ep_calculate_readiness($input);
        if (!$calc['ok']) {
            return ['ok' => false, 'message' => implode(' ', $calc['errors']), 'calc' => $calc];
        }
        $d = $calc['data'];
        $now = date('Y-m-d H:i:s');
        $actor = ep_current_actor();
        $recs = json_encode($d['recommendations'] ?? [], JSON_UNESCAPED_UNICODE);
        $ps = (int)$d['product_score'];
        $ms = (int)$d['market_score'];
        $cs = (int)$d['costing_score'];
        $cap = (int)$d['capacity_score'];
        $comp = (int)$d['compliance_score'];
        $ts = (int)$d['team_score'];
        $total = (float)$d['total_score'];
        $level = (string)$d['readiness_level'];

        $stmt = $db->prepare("INSERT INTO enterprise_opportunity_readiness (
            enterprise_opportunity_id, product_score, market_score, costing_score, capacity_score,
            compliance_score, team_score, total_score, readiness_level, recommendations_json,
            completed_by, assessed_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            product_score=VALUES(product_score), market_score=VALUES(market_score), costing_score=VALUES(costing_score),
            capacity_score=VALUES(capacity_score), compliance_score=VALUES(compliance_score), team_score=VALUES(team_score),
            total_score=VALUES(total_score), readiness_level=VALUES(readiness_level),
            recommendations_json=VALUES(recommendations_json), completed_by=VALUES(completed_by), assessed_at=VALUES(assessed_at)");
        $stmt->bind_param(
            'iiiiiiidssss',
            $opportunityId, $ps, $ms, $cs, $cap, $comp, $ts, $total, $level, $recs, $actor, $now
        );
        $stmt->execute();
        $stmt->close();

        $upd = $db->prepare('UPDATE enterprise_opportunities SET readiness_score=?, readiness_level=?, updated_at=NOW() WHERE id=?');
        $upd->bind_param('dsi', $total, $level, $opportunityId);
        $upd->execute();
        $upd->close();

        ep_audit($db, 'enterprise_portal.readiness_saved', ['opportunity_id' => $opportunityId, 'score' => $total]);
        return ['ok' => true, 'message' => 'Readiness assessment saved.', 'calc' => $calc];
    }
}

if (!function_exists('ep_get_readiness')) {
    function ep_get_readiness(mysqli $db, int $opportunityId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM enterprise_opportunity_readiness WHERE enterprise_opportunity_id = ? LIMIT 1');
        $stmt->bind_param('i', $opportunityId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
