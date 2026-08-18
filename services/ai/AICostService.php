<?php
declare(strict_types=1);

final class AICostService
{
    private const INPUT_PER_MILLION = 0.15;
    private const OUTPUT_PER_MILLION = 0.60;

    public static function estimateTokens(string $text): int
    {
        return max(1, (int)ceil(strlen($text) / 4));
    }

    public static function estimatedCost(int $promptTokens, int $completionTokens, string $model): float
    {
        if (str_starts_with($model, 'ollama:') || $model === 'verified-answer' || $model === 'grounded-fallback') {
            return 0.0;
        }
        return round(($promptTokens / 1000000 * self::INPUT_PER_MILLION)
            + ($completionTokens / 1000000 * self::OUTPUT_PER_MILLION), 6);
    }

    public function __construct(private mysqli $db) {}

    public function log(array $usage): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ai_usage_logs
             (user_id, department_id, course_id, conversation_id, request_type, model_name,
              prompt_tokens, completion_tokens, estimated_cost, latency_ms, cache_hit)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $departmentId = $usage['department_id'] ?? null;
        $courseId = $usage['course_id'] ?? null;
        $conversationId = isset($usage['conversation_id']) ? (int)$usage['conversation_id'] : null;
        $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int)($usage['completion_tokens'] ?? 0);
        $cost = (float)($usage['estimated_cost'] ?? 0);
        $latency = (int)($usage['latency_ms'] ?? 0);
        $cacheHit = !empty($usage['cache_hit']) ? 1 : 0;
        $stmt->bind_param(
            'sssissiidii',
            $usage['user_id'], $departmentId, $courseId, $conversationId,
            $usage['request_type'], $usage['model_name'], $promptTokens, $completionTokens,
            $cost, $latency, $cacheHit
        );
        $stmt->execute();
        $stmt->close();
    }
}
