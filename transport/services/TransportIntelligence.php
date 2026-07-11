<?php

final class TransportIntelligence
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function snapshot(): array
    {
        $metrics = [
            'open_incidents' => $this->count("SELECT COUNT(*) FROM transport_incident_reports WHERE status <> 'resolved'"),
            'unfit_checks_30d' => $this->count("SELECT COUNT(*) FROM transport_preuse_checks WHERE checklist_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND overall_status = 'unfit'"),
            'fleet_unavailable' => $this->count("SELECT COUNT(*) FROM transport_vehicles WHERE status IN ('maintenance','unavailable')"),
            'documents_expired' => $this->count("SELECT COUNT(*) FROM transport_vehicles WHERE (fitness_expiry IS NOT NULL AND fitness_expiry < CURDATE()) OR (insurance_expiry IS NOT NULL AND insurance_expiry < CURDATE())")
                + $this->count("SELECT COUNT(*) FROM transport_instructors WHERE status = 'active' AND ((rtsa_expiry IS NOT NULL AND rtsa_expiry < CURDATE()) OR (teveta_expiry IS NOT NULL AND teveta_expiry < CURDATE()))"),
            'documents_due_30d' => $this->count("SELECT COUNT(*) FROM transport_vehicles WHERE (fitness_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (insurance_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
                + $this->count("SELECT COUNT(*) FROM transport_instructors WHERE status = 'active' AND ((rtsa_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (teveta_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)))"),
            'overdue_maintenance' => $this->count("SELECT COUNT(*) FROM transport_maintenance_logs WHERE status = 'overdue' OR (status = 'scheduled' AND service_date < CURDATE())"),
            'parts_reorder' => $this->count("SELECT COUNT(*) FROM transport_parts_inventory WHERE status = 'reorder' OR stock_level <= reorder_level"),
            'sessions_next_7d' => $this->count("SELECT COUNT(*) FROM transport_sessions WHERE status = 'scheduled' AND session_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"),
        ];

        $priorities = [];
        $this->addPriority($priorities, $metrics['open_incidents'], 'critical', 'Resolve open incident reports', 'Incident records need review before affected vehicles return to normal operation.', '/wucportal/transport/fleet.php');
        $this->addPriority($priorities, $metrics['unfit_checks_30d'], 'critical', 'Investigate failed pre-use checks', 'Unfit inspections were recorded during the last 30 days.', '/wucportal/transport/preuse_checks.php');
        $this->addPriority($priorities, $metrics['documents_expired'], 'critical', 'Renew expired compliance documents', 'Vehicle or instructor compliance documents have expired.', '/wucportal/transport/fleet.php');
        $this->addPriority($priorities, $metrics['fleet_unavailable'], 'warning', 'Recover unavailable fleet assets', 'Vehicles are in maintenance or unavailable status.', '/wucportal/transport/fleet.php');
        $this->addPriority($priorities, $metrics['documents_due_30d'], 'warning', 'Plan upcoming renewals', 'Compliance documents expire within 30 days.', '/wucportal/transport/fleet.php');
        $this->addPriority($priorities, $metrics['overdue_maintenance'], 'warning', 'Complete overdue maintenance', 'Scheduled maintenance dates have passed.', '/wucportal/transport/fleet.php');
        $this->addPriority($priorities, $metrics['parts_reorder'], 'warning', 'Replenish low-stock parts', 'Inventory has reached its reorder threshold.', '/wucportal/transport/fleet.php');

        if (!$priorities) {
            $priorities[] = [
                'severity' => 'good',
                'count' => 0,
                'title' => 'No immediate operational risks detected',
                'detail' => $metrics['sessions_next_7d'] > 0
                    ? $metrics['sessions_next_7d'] . ' session(s) are scheduled in the next seven days.'
                    : 'Add fleet, instructors, cohorts, and sessions to begin live risk monitoring.',
                'url' => '/wucportal/transport/transport_management.php',
            ];
        }

        $riskPoints = ($metrics['open_incidents'] * 30)
            + ($metrics['unfit_checks_30d'] * 25)
            + ($metrics['documents_expired'] * 20)
            + ($metrics['fleet_unavailable'] * 10)
            + ($metrics['documents_due_30d'] * 5)
            + ($metrics['overdue_maintenance'] * 10)
            + ($metrics['parts_reorder'] * 3);
        $score = max(0, 100 - min(100, $riskPoints));

        return [
            'generated_at' => date(DATE_ATOM),
            'score' => $score,
            'label' => $score >= 85 ? 'Healthy' : ($score >= 65 ? 'Watch' : 'Action required'),
            'metrics' => $metrics,
            'priorities' => array_slice($priorities, 0, 6),
        ];
    }

    public function assistantStatus(): array
    {
        try {
            require_once __DIR__ . '/../../ai/ollama.php';
            $available = ollama_available();
            $model = $available ? ai_resolve_chat_model() : null;
            return ['available' => $available, 'model' => $model];
        } catch (Throwable $e) {
            error_log('Transport AI status check failed: ' . $e->getMessage());
            return ['available' => false, 'model' => null];
        }
    }

    public function executiveBrief(array $snapshot): array
    {
        $fallback = $this->deterministicBrief($snapshot);
        $status = $this->assistantStatus();
        if (!$status['available']) {
            return ['text' => $fallback, 'source' => 'rules', 'model' => null];
        }

        $payload = json_encode([
            'safety_score' => $snapshot['score'],
            'status' => $snapshot['label'],
            'metrics' => $snapshot['metrics'],
            'priorities' => array_map(static fn(array $item): array => [
                'severity' => $item['severity'],
                'count' => $item['count'],
                'title' => $item['title'],
            ], $snapshot['priorities']),
        ], JSON_UNESCAPED_SLASHES);

        try {
            $text = ollama_chat([
                ['role' => 'system', 'content' => 'You are a university transport operations analyst. Use only the supplied aggregate facts. Write a concise three-sentence briefing: current condition, highest priority, and next action. Do not invent names, causes, dates, or predictions. Return plain text only.'],
                ['role' => 'user', 'content' => (string)$payload],
            ], (string)$status['model']);
            $text = trim((string)preg_replace('/<think>.*?<\/think>/is', '', $text));
            if (!$this->isGroundedBrief($text, $snapshot)) {
                throw new RuntimeException('The local model returned an off-topic or ungrounded briefing.');
            }
            return ['text' => $text, 'source' => 'local_ai', 'model' => $status['model']];
        } catch (Throwable $e) {
            error_log('Transport AI briefing failed: ' . $e->getMessage());
            return ['text' => $fallback, 'source' => 'rules', 'model' => null];
        }
    }

    private function deterministicBrief(array $snapshot): string
    {
        $top = $snapshot['priorities'][0] ?? null;
        if (!$top || $top['severity'] === 'good') {
            return 'Transport operations show no immediate compliance or safety exception. Keep pre-use checks, maintenance records, and session schedules current so the risk view remains reliable.';
        }
        return sprintf(
            'Transport operations are rated %s with a safety score of %d/100. Highest priority: %s (%d). Review the linked records and record corrective action before scheduling affected resources.',
            strtolower((string)$snapshot['label']),
            (int)$snapshot['score'],
            (string)$top['title'],
            (int)$top['count']
        );
    }

    private function isGroundedBrief(string $text, array $snapshot): bool
    {
        $length = strlen($text);
        if ($length < 40 || $length > 1800) {
            return false;
        }

        $lower = strtolower($text);
        foreach (['question bank', 'course:', 'suggested marks', 'marking guide', 'answer:'] as $blockedPhrase) {
            if (strpos($lower, $blockedPhrase) !== false) {
                return false;
            }
        }

        $mentionsDomain = strpos($lower, 'transport') !== false
            || strpos($lower, 'fleet') !== false
            || strpos($lower, 'operations') !== false;
        $mentionsEvidence = strpos($lower, (string)(int)$snapshot['score']) !== false
            || strpos($lower, strtolower((string)$snapshot['label'])) !== false
            || strpos($lower, 'no immediate') !== false;

        return $mentionsDomain && $mentionsEvidence;
    }

    private function count(string $sql): int
    {
        try {
            $result = $this->db->query($sql);
            return $result ? (int)$result->fetch_row()[0] : 0;
        } catch (Throwable $e) {
            error_log('Transport intelligence query failed: ' . $e->getMessage());
            return 0;
        }
    }

    private function addPriority(array &$items, int $count, string $severity, string $title, string $detail, string $url): void
    {
        if ($count <= 0) {
            return;
        }
        $items[] = compact('severity', 'count', 'title', 'detail', 'url');
    }
}
