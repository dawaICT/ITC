<?php
declare(strict_types=1);

/**
 * Free cloud AI backend for the WUC portal (OpenAI-compatible) with provider
 * failover.
 *
 * The portal tries an ordered CHAIN of providers and uses the first that
 * succeeds:
 *
 *   - With a free API key configured  -> chain = [groq, openrouter-qwen]
 *   - With no key                     -> cloud chain is empty (use Ollama or
 *     add ai/cloud_key.txt). Pollinations legacy text API is no longer a
 *     reliable keyless default (HTTP 402 / deprecation).
 *
 * Each provider call retries transient failures (HTTP 429, 5xx, transport
 * errors) with backoff before the chain moves on.
 *
 * SECRET HANDLING — keyed providers read the API key from, in order:
 *   1. environment variable WUC_AI_CLOUD_API_KEY
 *   2. the file ai/cloud_key.txt  (first non-comment line)
 * Keep cloud_key.txt out of version control.
 *
 * Env overrides:
 *   WUC_AI_CLOUD_CHAIN     comma list, e.g. "groq,openrouter-qwen"
 *   WUC_AI_CLOUD_PROVIDER  force a single primary provider
 *   WUC_AI_CLOUD_MODEL / _BASE_URL / _ENDPOINT   override the primary provider
 *   WUC_AI_CLOUD_RETRIES   transient retries per provider (default 3)
 *   WUC_AI_CLOUD_TIMEOUT   per-request seconds (default 90)
 */

if (!function_exists('wuc_ai_cloud_profiles')) {
    function wuc_ai_cloud_profiles(): array
    {
        return [
            // Free tier, fast — needs a free key from https://console.groq.com/keys
            'groq' => [
                'base_url'     => 'https://api.groq.com/openai/v1',
                'endpoint'     => '/chat/completions',
                'model'        => 'llama-3.1-8b-instant',
                'requires_key' => true,
            ],
            'openrouter' => [
                'base_url'     => 'https://openrouter.ai/api/v1',
                'endpoint'     => '/chat/completions',
                'model'        => 'meta-llama/llama-3.1-8b-instruct:free',
                'requires_key' => true,
            ],
            'openrouter-qwen' => [
                'base_url'     => 'https://openrouter.ai/api/v1',
                'endpoint'     => '/chat/completions',
                'model'        => 'qwen/qwen-2.5-72b-instruct:free',
                'requires_key' => true,
            ],
            'together' => [
                'base_url'     => 'https://api.together.xyz/v1',
                'endpoint'     => '/chat/completions',
                'model'        => 'meta-llama/Llama-3.1-8B-Instruct-Turbo',
                'requires_key' => true,
            ],
            // Pollinations now requires auth / pollen balance — only use when
            // explicitly selected and a key is supplied (enter.pollinations.ai).
            'pollinations' => [
                'base_url'     => 'https://gen.pollinations.ai',
                'endpoint'     => '/v1/chat/completions',
                'model'        => 'openai',
                'requires_key' => true,
            ],
        ];
    }
}

if (!function_exists('wuc_ai_cloud_read_key')) {
    function wuc_ai_cloud_read_key(): string
    {
        $envKey = getenv('WUC_AI_CLOUD_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }

        $keyFile = dirname(__DIR__) . '/ai/cloud_key.txt';
        if (is_file($keyFile)) {
            $raw = (string)@file_get_contents($keyFile);
            foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && strpos($line, '#') !== 0) {
                    // Ignore the placeholder from the example file if copied as-is.
                    if (stripos($line, 'PASTE_YOUR') !== false || stripos($line, 'YOUR_') === 0) {
                        continue;
                    }
                    return $line;
                }
            }
        }
        return '';
    }
}

if (!function_exists('wuc_ai_cloud_chain')) {
    /**
     * Ordered list of resolved provider configs. Keyed providers without a key
     * are skipped. Without any usable key the chain is empty — callers fall
     * back to local Ollama or static text.
     */
    function wuc_ai_cloud_chain(): array
    {
        static $chain = null;
        if ($chain !== null) {
            return $chain;
        }

        $profiles = wuc_ai_cloud_profiles();
        $key = wuc_ai_cloud_read_key();

        $order = [];
        $envChain = trim((string)(getenv('WUC_AI_CLOUD_CHAIN') ?: ''));
        if ($envChain !== '') {
            $order = array_map('trim', explode(',', strtolower($envChain)));
        } else {
            $primary = strtolower((string)(getenv('WUC_AI_CLOUD_PROVIDER') ?: ''));
            if ($primary !== '') {
                $order[] = $primary;
            } elseif ($key !== '') {
                $order[] = 'groq';
                $order[] = 'openrouter-qwen';
            }
            // Do not auto-append pollinations — legacy keyless endpoint returns HTTP 402.
        }
        $order = array_values(array_unique(array_filter($order)));

        $timeout = (int)(getenv('WUC_AI_CLOUD_TIMEOUT') ?: 90);
        $resolved = [];
        foreach ($order as $i => $name) {
            $profile = $profiles[$name] ?? null;
            if ($profile === null) {
                continue;
            }
            $isPrimary = ($i === 0);
            $base     = $isPrimary ? (getenv('WUC_AI_CLOUD_BASE_URL') ?: $profile['base_url']) : $profile['base_url'];
            $endpoint = $isPrimary ? (getenv('WUC_AI_CLOUD_ENDPOINT') ?: $profile['endpoint']) : $profile['endpoint'];
            $model    = $isPrimary ? (getenv('WUC_AI_CLOUD_MODEL') ?: $profile['model']) : $profile['model'];

            $cfg = [
                'provider'     => $name,
                'base_url'     => rtrim((string)$base, '/'),
                'endpoint'     => '/' . ltrim((string)$endpoint, '/'),
                'model'        => (string)$model,
                'requires_key' => (bool)$profile['requires_key'],
                'api_key'      => $key,
                'timeout'      => $timeout,
            ];
            if ($cfg['requires_key'] && $cfg['api_key'] === '') {
                continue;
            }
            $resolved[] = $cfg;
        }

        $chain = $resolved;
        return $chain;
    }
}

if (!function_exists('wuc_ai_cloud_config')) {
    /** Primary (first) provider config, for status/labels. */
    function wuc_ai_cloud_config(): array
    {
        $chain = wuc_ai_cloud_chain();
        if ($chain !== []) {
            return $chain[0];
        }
        return [
            'provider' => 'none',
            'base_url' => '',
            'endpoint' => '/chat/completions',
            'model'    => '',
            'requires_key' => true,
            'api_key'  => '',
            'timeout'  => 90,
        ];
    }
}

if (!function_exists('wuc_ai_cloud_enabled')) {
    function wuc_ai_cloud_enabled(): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        return wuc_ai_cloud_chain() !== [];
    }
}

if (!function_exists('wuc_ai_cloud_label')) {
    function wuc_ai_cloud_label(): string
    {
        $chain = wuc_ai_cloud_chain();
        if ($chain === []) {
            return 'cloud:unavailable';
        }
        $primary = $chain[0]['provider'] . ':' . $chain[0]['model'];
        if (count($chain) > 1) {
            $primary .= ' (+' . (count($chain) - 1) . ' fallback)';
        }
        return $primary;
    }
}

if (!function_exists('wuc_ai_cloud_setup_hint')) {
    function wuc_ai_cloud_setup_hint(): string
    {
        return 'Install Ollama and run ai\\setup_ollama.ps1 (pulls llama3.2:3b + nomic-embed-text), or add a free Groq key to ai/cloud_key.txt. Pollinations keyless text API is deprecated (HTTP 402).';
    }
}

if (!function_exists('wuc_ai_cloud_request')) {
    /**
     * Single-provider request with transient retry. Throws on failure.
     */
    function wuc_ai_cloud_request(array $cfg, array $messages, int $maxAttempts): string
    {
        $payload = [
            'model'       => $cfg['model'],
            'messages'    => array_values($messages),
            'temperature' => 0.4,
            'stream'      => false,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];
        if ($cfg['api_key'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $cfg['api_key'];
        }
        if (($cfg['provider'] ?? '') === 'openrouter' || ($cfg['provider'] ?? '') === 'openrouter-qwen') {
            $headers[] = 'HTTP-Referer: http://localhost/wucportal/';
            $headers[] = 'X-Title: ITC WUC Portal';
        }

        $url = $cfg['base_url'] . $cfg['endpoint'];
        $maxAttempts = max(1, min(5, $maxAttempts));
        $body = false;
        $code = 0;
        $lastErr = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $maxExec = (int)ini_get('max_execution_time');
            $needed = max(10, (int)$cfg['timeout']) + 30;
            if ($maxExec > 0 && $needed > $maxExec) {
                @set_time_limit($needed);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $payloadJson,
                CURLOPT_TIMEOUT        => max(10, (int)$cfg['timeout']),
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $body = curl_exec($ch);
            $transportErr = ($body === false) ? curl_error($ch) : '';
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 402 is permanent for pollen/payment — do not retry the same provider.
            $transient = ($body === false) || $code === 429 || ($code >= 500 && $code <= 599);
            if ($transient && $attempt < $maxAttempts) {
                $lastErr = $transportErr !== '' ? $transportErr : ('HTTP ' . $code);
                $delay = (2 * $attempt) + (mt_rand(0, 500) / 1000);
                usleep((int)round($delay * 1_000_000));
                continue;
            }
            break;
        }

        if ($body === false) {
            throw new RuntimeException("Cannot reach {$cfg['provider']} after {$maxAttempts} attempt(s): " . ($lastErr ?: 'transport error'));
        }

        $data = json_decode((string)$body, true);
        if ($code !== 200) {
            $msg = is_array($data) ? ($data['error']['message'] ?? ($data['error'] ?? $body)) : $body;
            if (is_array($msg)) {
                $msg = json_encode($msg);
            }
            if ($code === 402) {
                throw new RuntimeException(
                    "{$cfg['provider']} requires a paid balance or valid API key (HTTP 402). "
                    . wuc_ai_cloud_setup_hint()
                );
            }
            throw new RuntimeException("{$cfg['provider']} API error (HTTP {$code}): " . (string)$msg);
        }

        if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
            return trim((string)$data['choices'][0]['message']['content']);
        }
        if (!is_array($data) && trim((string)$body) !== '') {
            return trim((string)$body);
        }
        throw new RuntimeException("{$cfg['provider']} returned an unexpected response shape.");
    }
}

if (!function_exists('wuc_ai_cloud_chat')) {
    /**
     * Try each provider in the chain; return the first success. Throws only if
     * every provider fails (caller then uses its own text fallback).
     *
     * @param array $messages [['role'=>'system'|'user'|'assistant','content'=>'...'], ...]
     */
    function wuc_ai_cloud_chat(array $messages): string
    {
        $chain = wuc_ai_cloud_chain();
        if ($chain === []) {
            throw new RuntimeException('No cloud AI provider is configured. ' . wuc_ai_cloud_setup_hint());
        }

        $baseRetries = (int)(getenv('WUC_AI_CLOUD_RETRIES') ?: 3);
        $perProvider = count($chain) > 1 ? max(1, min($baseRetries, 2)) : $baseRetries;

        $reopenSession = false;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            $reopenSession = true;
        }

        try {
            $errors = [];
            foreach ($chain as $cfg) {
                try {
                    return wuc_ai_cloud_request($cfg, $messages, $perProvider);
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    error_log('wuc_ai_cloud_chat: provider ' . $cfg['provider'] . ' failed: ' . $e->getMessage());
                }
            }

            throw new RuntimeException('All cloud AI providers failed: ' . implode(' | ', $errors));
        } finally {
            if ($reopenSession && session_status() === PHP_SESSION_NONE && !headers_sent()) {
                @session_start();
            }
        }
    }
}

if (!function_exists('wuc_ai_cloud_status')) {
    function wuc_ai_cloud_status(): array
    {
        $chain = wuc_ai_cloud_chain();
        $enabled = $chain !== [] && function_exists('curl_init');
        $providers = array_map(static fn($c) => $c['provider'] . ':' . $c['model'], $chain);
        return [
            'enabled'   => $enabled,
            'provider'  => $chain[0]['provider'] ?? 'none',
            'model'     => $chain[0]['model'] ?? '',
            'label'     => wuc_ai_cloud_label(),
            'chain'     => $providers,
            'message'   => $enabled
                ? 'Cloud AI ready. Chain: ' . implode(' -> ', $providers)
                : 'Cloud AI not configured. ' . wuc_ai_cloud_setup_hint(),
        ];
    }
}
