<?php
declare(strict_types=1);

if (!function_exists('eh_ai_assist')) {
    /**
     * Controlled AI assistant. Never auto-saves. Never approves/publishes.
     *
     * @return array{ok:bool, text:string, status:string, message:string}
     */
    function eh_ai_assist(mysqli $db, string $task, array $context = []): array
    {
        if (!eh_ai_enabled($db)) {
            return [
                'ok' => false,
                'text' => '',
                'status' => 'disabled',
                'message' => 'AI assistance is disabled. You can continue without it.',
            ];
        }

        $allowed = [
            'improve_description',
            'short_summary',
            'target_customers',
            'marketing_wording',
            'missing_information',
            'explain_costs',
            'readiness_improvements',
            'investor_summary',
            'investor_questions',
        ];
        if (!in_array($task, $allowed, true)) {
            return ['ok' => false, 'text' => '', 'status' => 'invalid', 'message' => 'Unknown AI task.'];
        }

        // Strip sensitive personal data from context
        $safe = [
            'title' => substr(strip_tags((string)($context['title'] ?? '')), 0, 200),
            'item_type' => substr((string)($context['item_type'] ?? ''), 0, 40),
            'category' => substr((string)($context['category'] ?? ''), 0, 120),
            'short_description' => substr(strip_tags((string)($context['short_description'] ?? '')), 0, 500),
            'full_description' => substr(strip_tags((string)($context['full_description'] ?? '')), 0, 2000),
            'readiness_level' => substr((string)($context['readiness_level'] ?? ''), 0, 60),
            'cost_summary' => substr(strip_tags((string)($context['cost_summary'] ?? '')), 0, 500),
        ];

        $prompts = [
            'improve_description' => 'Improve this product/service description for a vocational trade showcase. Keep facts, use plain professional language, do not invent prices or guarantees.',
            'short_summary' => 'Write a short public summary (max 60 words) suitable for a catalogue card.',
            'target_customers' => 'Suggest likely target customers in Zambia for this opportunity. Do not invent specific named companies.',
            'marketing_wording' => 'Suggest marketing wording for exhibition visitors. No guaranteed claims.',
            'missing_information' => 'List missing business information that would strengthen this opportunity before publication.',
            'explain_costs' => 'Explain the cost and profit figures in plain language for a student entrepreneur. Remind that figures are estimates.',
            'readiness_improvements' => 'Suggest practical readiness improvements based on the readiness level. Do not claim approval.',
            'investor_summary' => 'Draft an investor-facing summary. Mark it as a draft. No guaranteed returns.',
            'investor_questions' => 'List likely investor questions for this opportunity.',
        ];

        $userPrompt = $prompts[$task] . "\n\nContext:\n" . json_encode($safe, JSON_UNESCAPED_UNICODE);

        if (!function_exists('wuc_ai_generate')) {
            require_once dirname(__DIR__) . '/ai_portal.php';
        }

        $fallback = static function () use ($task, $safe): string {
            return match ($task) {
                'short_summary' => trim(($safe['title'] ?: 'Opportunity') . ' — ' . ($safe['short_description'] ?: 'Vocational enterprise opportunity from ITC training.')),
                'missing_information' => "Review: clear unit price, production capacity, photos, registration status, and evidence of demand.",
                'explain_costs' => "Costs are estimates from materials, labour and related inputs. Selling price minus cost per unit is estimated profit. These figures are not an investment guarantee.",
                default => 'AI is offline. Use the form fields to refine your description manually.',
            };
        };

        try {
            $result = wuc_ai_generate($db, [
                'feature' => 'enterprise_hub_' . $task,
                'user_role' => (string)($_SESSION['user_role'] ?? 'unknown'),
                'user_id' => eh_current_actor_id(),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You assist ITC Skills-to-Trade Hub users. Never approve, reject, verify or publish records. Never invent personal data, student numbers, NRC, or guaranteed financial outcomes. Mark content as draft suggestions. No emojis.',
                    ],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'fallback' => $fallback,
                'log_content' => false,
                'context_hash' => hash('sha256', $task . '|' . ($safe['title'] ?? '')),
            ]);
            $text = trim(strip_tags((string)($result['text'] ?? '')));
            eh_audit($db, 'enterprise_hub.ai_assist', [
                'task' => $task,
                'status' => (string)($result['status'] ?? 'unknown'),
            ]);
            return [
                'ok' => $text !== '',
                'text' => $text,
                'status' => (string)($result['status'] ?? 'ok'),
                'message' => 'AI draft generated. Review and accept manually — nothing is saved automatically.',
            ];
        } catch (Throwable $e) {
            $text = $fallback();
            return [
                'ok' => true,
                'text' => $text,
                'status' => 'fallback',
                'message' => 'AI unavailable; showing a local draft suggestion.',
            ];
        }
    }
}
