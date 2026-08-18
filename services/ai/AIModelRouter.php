<?php
declare(strict_types=1);

final class AIModelRouter
{
    private ?mysqli $db;
    private $generator;

    public function __construct(mysqli|callable|null $dbOrGenerator = null, ?callable $generator = null)
    {
        // Backward compatible: tests historically passed a callable as the first argument.
        if (is_callable($dbOrGenerator)) {
            $this->db = null;
            $this->generator = $dbOrGenerator;
        } else {
            $this->db = $dbOrGenerator;
            $this->generator = $generator;
        }
    }

    public function generate(string $systemPrompt, string $userPrompt, string $requestType = 'chat'): array
    {
        if ($this->generator !== null) {
            return ['answer' => (string)call_user_func($this->generator, $systemPrompt, $userPrompt, $requestType), 'model' => 'test:controlled'];
        }

        // Prefer the shared portal AI entry point (local Ollama + free cloud failover).
        // Learning Assistant previously called Ollama only, so a missing local install
        // failed even when Pollinations/Groq were available.
        if ($this->db instanceof mysqli) {
            require_once dirname(__DIR__, 2) . '/includes/ai_portal.php';
            $result = wuc_ai_generate($this->db, [
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'feature' => 'learning_assistant_' . $requestType,
                'action' => 'generate',
                'user_role' => 'student',
                'fallback' => static fn(): string => '',
            ]);
            $answer = trim((string)($result['text'] ?? ''));
            if (!empty($result['used_ai']) && $answer !== '') {
                $provider = (string)($result['provider'] ?? 'ai');
                $model = (string)($result['model'] ?? 'unknown');
                return ['answer' => $answer, 'model' => $provider . ':' . $model];
            }
            $detail = trim((string)($result['error'] ?? ''));
            throw new RuntimeException($detail !== '' ? $detail : 'The configured AI model is currently unavailable.');
        }

        require_once dirname(__DIR__, 2) . '/ai/ollama.php';
        if (!ollama_available()) {
            throw new RuntimeException('The configured AI model is currently unavailable.');
        }
        $preferred = getenv($requestType === 'summary' ? 'WUC_AI_LOW_COST_MODEL' : 'WUC_AI_STANDARD_MODEL') ?: null;
        $model = ai_resolve_chat_model($preferred ?: null);
        $answer = ollama_chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ], $model);
        return ['answer' => $answer, 'model' => 'ollama:' . $model];
    }
}
