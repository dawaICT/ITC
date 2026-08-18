<?php
declare(strict_types=1);

if (!function_exists('eh_readiness_weights')) {
    /** @return array<string,float> */
    function eh_readiness_weights(): array
    {
        return [
            'product_score' => 0.20,
            'market_score' => 0.20,
            'costing_score' => 0.20,
            'capacity_score' => 0.15,
            'compliance_score' => 0.15,
            'team_score' => 0.10,
        ];
    }
}

if (!function_exists('eh_readiness_level_from_score')) {
    function eh_readiness_level_from_score(float $score): string
    {
        if ($score < 40) {
            return 'Early Stage';
        }
        if ($score < 60) {
            return 'Development Required';
        }
        if ($score < 75) {
            return 'Market Ready';
        }
        if ($score < 90) {
            return 'Investment Preparation';
        }
        return 'Strong Investment Readiness';
    }
}

if (!function_exists('eh_readiness_recommendations')) {
    /** @param array<string,int> $scores */
    function eh_readiness_recommendations(array $scores): array
    {
        $recs = [];
        if (($scores['costing_score'] ?? 100) < 60) {
            $recs[] = 'Complete a verified cost breakdown before approaching buyers.';
        }
        if (($scores['compliance_score'] ?? 100) < 60) {
            $recs[] = 'Review business registration and applicable sector requirements.';
        }
        if (($scores['capacity_score'] ?? 100) < 60) {
            $recs[] = 'Document equipment, suppliers and monthly production limits.';
        }
        if (($scores['market_score'] ?? 100) < 60) {
            $recs[] = 'Identify target customers and record evidence of demand.';
        }
        if (($scores['product_score'] ?? 100) < 60) {
            $recs[] = 'Strengthen product quality evidence, specifications and sample availability.';
        }
        if (($scores['team_score'] ?? 100) < 60) {
            $recs[] = 'Clarify roles, skills and day-to-day operational responsibilities.';
        }
        if ($recs === []) {
            $recs[] = 'Maintain documentation and continue preparing for market engagement.';
        }
        return $recs;
    }
}

if (!function_exists('eh_calculate_readiness')) {
    /** @param array<string,mixed> $input */
    function eh_calculate_readiness(array $input): array
    {
        $errors = [];
        $scores = [];
        foreach (array_keys(eh_readiness_weights()) as $key) {
            $raw = $input[$key] ?? null;
            if (!is_numeric($raw)) {
                $errors[] = ucwords(str_replace('_', ' ', $key)) . ' is required (0–100).';
                $scores[$key] = 0;
                continue;
            }
            $val = (int)$raw;
            if ($val < 0 || $val > 100) {
                $errors[] = ucwords(str_replace('_', ' ', $key)) . ' must be between 0 and 100.';
            }
            $scores[$key] = max(0, min(100, $val));
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'data' => []];
        }

        $total = 0.0;
        foreach (eh_readiness_weights() as $key => $weight) {
            $total += $scores[$key] * $weight;
        }
        $total = round($total, 2);
        $level = eh_readiness_level_from_score($total);
        $recs = eh_readiness_recommendations($scores);

        return [
            'ok' => true,
            'errors' => [],
            'data' => array_merge($scores, [
                'total_score' => $total,
                'readiness_level' => $level,
                'recommendations' => $recs,
            ]),
        ];
    }
}

if (!function_exists('eh_save_readiness')) {
    function eh_save_readiness(mysqli $db, int $itemId, array $input, string $completedBy): array
    {
        $calc = eh_calculate_readiness($input);
        if (!$calc['ok']) {
            return $calc;
        }
        $d = $calc['data'];
        $recsJson = json_encode($d['recommendations'], JSON_UNESCAPED_UNICODE);
        $now = date('Y-m-d H:i:s');
        $ps = (int)$d['product_score'];
        $ms = (int)$d['market_score'];
        $cs = (int)$d['costing_score'];
        $cap = (int)$d['capacity_score'];
        $comp = (int)$d['compliance_score'];
        $ts = (int)$d['team_score'];
        $total = (float)$d['total_score'];
        $level = (string)$d['readiness_level'];

        $stmt = $db->prepare("
            INSERT INTO enterprise_readiness_assessments (
                enterprise_item_id, product_score, market_score, costing_score, capacity_score,
                compliance_score, team_score, total_score, readiness_level, recommendations_json,
                completed_by, assessed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                product_score=VALUES(product_score), market_score=VALUES(market_score),
                costing_score=VALUES(costing_score), capacity_score=VALUES(capacity_score),
                compliance_score=VALUES(compliance_score), team_score=VALUES(team_score),
                total_score=VALUES(total_score), readiness_level=VALUES(readiness_level),
                recommendations_json=VALUES(recommendations_json), completed_by=VALUES(completed_by),
                assessed_at=VALUES(assessed_at)
        ");
        $stmt->bind_param(
            'iiiiiiidssss',
            $itemId, $ps, $ms, $cs, $cap, $comp, $ts, $total, $level, $recsJson, $completedBy, $now
        );
        $stmt->execute();
        $stmt->close();

        $u = $db->prepare('UPDATE enterprise_items SET readiness_score = ?, readiness_level = ?, updated_at = NOW() WHERE id = ?');
        $u->bind_param('dsi', $total, $level, $itemId);
        $u->execute();
        $u->close();

        eh_audit($db, 'enterprise_hub.readiness_assessed', [
            'item_id' => $itemId,
            'total_score' => $total,
            'level' => $level,
        ]);
        $calc['saved'] = true;
        return $calc;
    }
}
