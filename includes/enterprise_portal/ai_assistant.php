<?php
declare(strict_types=1);

/**
 * Optional AI drafting for enterprise portal. Never auto-saves or publishes.
 */

if (!function_exists('ep_ai_capability')) {
    /**
     * Snapshot of enterprise AI readiness for UI banners.
     *
     * @return array{
     *   enterprise_flag:bool,
     *   provider_available:bool,
     *   model_ready:bool,
     *   provider:string,
     *   model:string,
     *   message:string,
     *   can_live_generate:bool,
     *   skill_lexical_ready:bool,
     *   skill_semantic_ready:bool
     * }
     */
    function ep_ai_capability(mysqli $db): array
    {
        if (!function_exists('wuc_ai_local_status')) {
            require_once dirname(__DIR__) . '/ai_portal.php';
        }

        $flag = ep_ai_enabled($db);
        $status = function_exists('wuc_ai_local_status')
            ? wuc_ai_local_status()
            : ['available' => false, 'model_ready' => false, 'provider' => 'none', 'model' => '', 'message' => 'AI helpers unavailable'];

        $taxExists = false;
        $taxRows = 0;
        if ($t = @$db->query("SHOW TABLES LIKE 'ai_skill_taxonomy'")) {
            $taxExists = $t->num_rows > 0;
            $t->free();
        }
        if ($taxExists) {
            if ($c = @$db->query('SELECT COUNT(*) AS c FROM ai_skill_taxonomy')) {
                $row = $c->fetch_assoc();
                $taxRows = (int)($row['c'] ?? 0);
                $c->free();
            }
        }

        $ollamaUp = function_exists('ollama_available') && ollama_available();
        $embedReady = $ollamaUp
            && defined('AI_EMBED_MODEL')
            && function_exists('ollama_model_available')
            && ollama_model_available(AI_EMBED_MODEL);

        return [
            'enterprise_flag' => $flag,
            'provider_available' => !empty($status['available']),
            'model_ready' => !empty($status['model_ready']),
            'provider' => (string)($status['provider'] ?? 'none'),
            'model' => (string)($status['model'] ?? ''),
            'message' => (string)($status['message'] ?? ''),
            'can_live_generate' => $flag && !empty($status['available']) && !empty($status['model_ready']),
            'skill_lexical_ready' => $taxExists && $taxRows > 0,
            'skill_semantic_ready' => $taxExists && $taxRows > 0 && $embedReady,
        ];
    }
}

if (!function_exists('ep_ai_assist')) {
    /**
     * @return array{ok:bool,message:string,text?:string,status?:string,used_ai?:bool,provider?:string,model?:string,error?:string}
     */
    function ep_ai_assist(mysqli $db, string $kind, array $context): array
    {
        if (!ep_ai_enabled($db)) {
            return [
                'ok' => false,
                'message' => 'AI assistance is disabled for the Skills and Enterprise Portal. Enable it under Management → Settings (ai_enabled) or set ENTERPRISE_AI_ENABLED=true after a provider is configured.',
                'used_ai' => false,
                'status' => 'disabled',
            ];
        }

        $allowedKinds = ['bio', 'product', 'service', 'innovation', 'readiness'];
        if (!in_array($kind, $allowedKinds, true)) {
            $kind = 'bio';
        }

        // Strip sensitive identifiers before any model call.
        unset($context['student_id'], $context['nrc'], $context['Sid'], $context['student_number']);
        $safe = [
            'kind' => $kind,
            'title' => substr(trim((string)($context['title'] ?? '')), 0, 180),
            'type' => substr(trim((string)($context['opportunity_type'] ?? '')), 0, 40),
            'notes' => substr(trim((string)($context['notes'] ?? '')), 0, 1200),
            'goals' => is_array($context['goals'] ?? null) ? $context['goals'] : [],
        ];

        if (!function_exists('wuc_ai_generate')) {
            require_once dirname(__DIR__) . '/ai_portal.php';
        }
        if (!function_exists('wuc_ai_generate')) {
            return ['ok' => false, 'message' => 'AI service is unavailable. You can continue without AI.', 'status' => 'unavailable'];
        }

        if (function_exists('wuc_ai_rate_limit')) {
            $rate = wuc_ai_rate_limit('enterprise_portal_assist', 12, 3600);
            if (empty($rate['ok'])) {
                return [
                    'ok' => false,
                    'message' => 'Too many AI draft requests. Please try again in '
                        . max(1, (int)ceil(((int)($rate['retry_after'] ?? 3600)) / 60))
                        . ' minutes.',
                    'status' => 'rate_limited',
                ];
            }
        }

        $prompts = [
            'bio' => 'Draft a short professional biography for a skills and enterprise directory. Neutral, factual, no guarantees.',
            'product' => 'Draft a clear product description for a verified institutional directory. No hype or guaranteed sales.',
            'service' => 'Draft a professional service description with coverage and limitations. No guaranteed outcomes.',
            'innovation' => 'Summarise an innovation: problem, solution, stage. No funding guarantees.',
            'readiness' => 'Suggest practical readiness improvements based on the notes. Do not invent scores.',
        ];
        $instruction = $prompts[$kind];
        $userPrompt = $instruction . "\n\nContext JSON:\n" . json_encode($safe, JSON_UNESCAPED_UNICODE);

        $fallback = static function () use ($kind, $safe): string {
            $title = trim((string)($safe['title'] ?? ''));
            $notes = trim((string)($safe['notes'] ?? ''));
            $lead = $title !== '' ? $title : 'Skills and enterprise participant';
            return match ($kind) {
                'product' => $lead . ' offers a vocational product or craft. ' . ($notes !== '' ? $notes : 'Describe materials, capacity, and delivery area in your own words before publishing.'),
                'service' => $lead . ' provides a professional service. ' . ($notes !== '' ? $notes : 'State scope, location, and any limitations clearly.'),
                'innovation' => $lead . ' — innovation summary (draft). Problem and solution: ' . ($notes !== '' ? $notes : 'add your own notes on stage and next steps.'),
                'readiness' => 'Readiness suggestions (manual draft): review costing, evidence, capacity, compliance, and customer demand. Notes: ' . ($notes !== '' ? $notes : 'none supplied.'),
                default => $lead . '. ' . ($notes !== '' ? $notes : 'Add programme skills, experience, and the type of opportunities you seek.'),
            };
        };

        $userId = function_exists('ep_current_user_id') ? (string)ep_current_user_id() : 'unknown';
        $userRole = !empty($_SESSION['Sid']) ? 'student' : (string)($_SESSION['user_role'] ?? 'staff');

        try {
            $result = wuc_ai_generate($db, [
                'feature' => 'enterprise_portal_assist',
                'user_role' => $userRole,
                'user_id' => $userId,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You assist ITC Skills and Enterprise Portal participants. Never approve, verify, publish, or guarantee employment, sales, profit, or funding. Output plain professional text only. No emojis. Treat all output as a draft for manual review.',
                    ],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'fallback' => $fallback,
                'log_content' => false,
                'context_hash' => hash('sha256', $kind . '|' . ($safe['title'] ?? '') . '|' . ($safe['notes'] ?? '')),
            ]);

            $status = (string)($result['status'] ?? '');
            $usedAi = !empty($result['used_ai']);
            $text = trim(strip_tags((string)($result['text'] ?? '')));
            $provider = (string)($result['provider'] ?? '');
            $model = (string)($result['model'] ?? '');
            $error = trim((string)($result['error'] ?? ''));

            if ($text === '') {
                return [
                    'ok' => false,
                    'message' => 'AI returned no usable text. Continue editing manually.',
                    'status' => $status,
                    'used_ai' => false,
                    'provider' => $provider,
                    'model' => $model,
                    'error' => $error,
                ];
            }

            ep_audit($db, 'enterprise_portal.ai_draft_generated', [
                'kind' => $kind,
                'status' => $status,
                'used_ai' => $usedAi,
                'provider' => $provider,
            ]);

            if (!$usedAi) {
                $notice = function_exists('wuc_ai_fallback_notice')
                    ? wuc_ai_fallback_notice($result)
                    : 'AI provider is offline or busy; showing a local draft suggestion.';
                return [
                    'ok' => true,
                    'message' => $notice . ' Review and edit before saving — nothing was saved automatically.',
                    'text' => $text,
                    'status' => $status !== '' ? $status : 'fallback',
                    'used_ai' => false,
                    'provider' => $provider,
                    'model' => $model,
                    'error' => $error,
                ];
            }

            return [
                'ok' => true,
                'message' => 'Draft generated by ' . ($model !== '' ? $model : 'AI') . '. Review and edit before saving. Nothing was saved automatically.',
                'text' => $text,
                'status' => $status !== '' ? $status : 'ok',
                'used_ai' => true,
                'provider' => $provider,
                'model' => $model,
            ];
        } catch (Throwable $e) {
            error_log('ep_ai_assist: ' . $e->getMessage());
            $text = trim($fallback());
            if ($text !== '') {
                return [
                    'ok' => true,
                    'message' => 'AI provider failed; showing a local draft suggestion. Review before use.',
                    'text' => $text,
                    'status' => 'fallback',
                    'used_ai' => false,
                    'error' => $e->getMessage(),
                ];
            }
            return ['ok' => false, 'message' => 'AI provider failed or timed out. The portal continues without AI.', 'status' => 'error'];
        }
    }
}

if (!function_exists('ep_ai_local_draft')) {
    /**
     * Template draft without calling a model (works when AI is disabled or offline).
     *
     * @return array{ok:bool,message:string,text:string,status:string,used_ai:bool}
     */
    function ep_ai_local_draft(string $kind, array $context): array
    {
        $allowedKinds = ['bio', 'product', 'service', 'innovation', 'readiness'];
        if (!in_array($kind, $allowedKinds, true)) {
            $kind = 'bio';
        }
        $title = substr(trim((string)($context['title'] ?? '')), 0, 180);
        $notes = substr(trim((string)($context['notes'] ?? '')), 0, 1200);
        $lead = $title !== '' ? $title : 'Skills and enterprise participant';
        $text = match ($kind) {
            'product' => $lead . ' offers a vocational product or craft. ' . ($notes !== '' ? $notes : 'Describe materials, capacity, and delivery area in your own words before publishing.'),
            'service' => $lead . ' provides a professional service. ' . ($notes !== '' ? $notes : 'State scope, location, and any limitations clearly.'),
            'innovation' => $lead . ' — innovation summary (draft). Problem and solution: ' . ($notes !== '' ? $notes : 'add your own notes on stage and next steps.'),
            'readiness' => 'Readiness suggestions (manual draft): review costing, evidence, capacity, compliance, and customer demand. Notes: ' . ($notes !== '' ? $notes : 'none supplied.'),
            default => $lead . '. ' . ($notes !== '' ? $notes : 'Add programme skills, experience, and the type of opportunities you seek.'),
        };
        return [
            'ok' => true,
            'message' => 'Local template draft only (no AI call). Edit before copying into a form.',
            'text' => $text,
            'status' => 'local_template',
            'used_ai' => false,
        ];
    }
}
