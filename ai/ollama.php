<?php
/**
 * Minimal Ollama client (cURL) for the local AI module.
 *
 * Exposes:
 *   ollama_available()      -> bool        : is the local server up?
 *   ollama_embed($text)     -> float[]     : embedding vector for a string
 *   ollama_embed_batch($a)  -> float[][]   : embeddings for an array of strings
 *   ollama_chat($messages)  -> string      : assistant reply (Phase 2)
 *
 * All calls hit localhost only. Throws RuntimeException on transport/model errors
 * so callers can decide how to surface failure.
 */

require_once __DIR__ . '/config.php';

/**
 * Low-level JSON POST to the Ollama HTTP API.
 */
function ollama_post(string $path, array $payload, int $timeout): array
{
    // Local inference can legitimately run for tens of seconds (AI_CHAT_TIMEOUT
    // is longer than PHP's max_execution_time). Without these two safeguards a
    // slow call fatals the request instead of reaching the caller's AI-offline
    // fallback, and — worse — it holds the session file lock the whole time, so
    // every other page the same browser opens blocks inside session_start()
    // until it too dies at the execution limit.
    $maxExec = (int)ini_get('max_execution_time');
    if ($maxExec > 0 && $timeout + 20 > $maxExec) {
        @set_time_limit($timeout + 20);
    }

    $reopenSession = false;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        $reopenSession = true;
    }

    try {
        $url = OLLAMA_HOST . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException(
                "Cannot reach Ollama at " . OLLAMA_HOST . " ($err). " .
                "Is the Ollama server running? Try 'ollama serve'."
            );
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } finally {
        // Callers (e.g. students/ai_chat_ajax.php) write chat history to
        // $_SESSION after this returns, so the lock must be re-acquired.
        if ($reopenSession && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }

    $data = json_decode($body, true);
    if ($code !== 200) {
        $msg = $data['error'] ?? $body;
        throw new RuntimeException("Ollama API error (HTTP $code): $msg");
    }
    if (!is_array($data)) {
        throw new RuntimeException("Ollama returned non-JSON response: $body");
    }
    return $data;
}

/**
 * Is the local Ollama server reachable?
 */
function ollama_available(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $ch = curl_init(OLLAMA_HOST . '/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    return $cached = $ok;
}

/**
 * Return locally installed Ollama models from /api/tags.
 */
function ollama_installed_models(): array
{
    $ch = curl_init(OLLAMA_HOST . '/api/tags');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Cannot read Ollama model list: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($body, true);
    if ($code !== 200 || !is_array($data)) {
        throw new RuntimeException("Ollama model list failed (HTTP $code): $body");
    }
    return $data['models'] ?? [];
}

/**
 * Check whether a named Ollama model is installed locally.
 *
 * Ollama may report models with an explicit ":latest" tag even when the
 * configured name omits it, so both forms are treated as the same model.
 */
function ollama_model_available(string $modelName): bool
{
    $modelName = trim($modelName);
    if ($modelName === '') {
        return false;
    }

    try {
        foreach (ollama_installed_models() as $model) {
            $installedName = (string)($model['model'] ?? $model['name'] ?? '');
            if ($installedName === $modelName) {
                return true;
            }
            if (strpos($modelName, ':') === false && $installedName === $modelName . ':latest') {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

/**
 * Resolve the chat model to use now.
 *
 * Priority:
 *   1. explicit function argument,
 *   2. configured AI_CHAT_MODEL if installed,
 *   3. configured fallback list if installed,
 *   4. first locally installed non-embedding model,
 *   5. configured AI_CHAT_MODEL so Ollama can return a useful pull error.
 */
function ai_resolve_chat_model(?string $preferred = null): string
{
    if ($preferred !== null && trim($preferred) !== '') {
        return trim($preferred);
    }

    $installed = [];
    try {
        foreach (ollama_installed_models() as $model) {
            $name = (string)($model['model'] ?? $model['name'] ?? '');
            if ($name !== '') {
                $installed[$name] = $model;
            }
        }
    } catch (Throwable $e) {
        return AI_CHAT_MODEL;
    }

    $configured = AI_CHAT_MODEL;
    if (isset($installed[$configured])) {
        return $configured;
    }

    foreach (AI_CHAT_MODEL_FALLBACKS as $candidate) {
        if (isset($installed[$candidate])) {
            return $candidate;
        }
    }

    foreach ($installed as $name => $model) {
        $capabilities = $model['capabilities'] ?? [];
        if (is_array($capabilities) && !in_array('embedding', $capabilities, true)) {
            return $name;
        }
    }

    return $configured;
}

/**
 * Embed a single string -> float vector of length AI_EMBED_DIM.
 */
function ollama_embed(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        throw new InvalidArgumentException("Cannot embed empty text");
    }
    $data = ollama_post('/api/embeddings', [
        'model'  => AI_EMBED_MODEL,
        'prompt' => $text,
    ], AI_EMBED_TIMEOUT);

    if (empty($data['embedding']) || !is_array($data['embedding'])) {
        throw new RuntimeException(
            "No embedding returned. Is the model pulled? Run: ollama pull " . AI_EMBED_MODEL
        );
    }
    return $data['embedding'];
}

/**
 * Embed many strings. Simple sequential loop — fine for offline batch loading.
 */
function ollama_embed_batch(array $texts): array
{
    $out = [];
    foreach ($texts as $key => $text) {
        $out[$key] = ollama_embed($text);
    }
    return $out;
}

/**
 * Phase-2 chat completion. $messages = [['role'=>'user','content'=>'...'], ...].
 * Returns the assistant's text. Slow on a CPU-only machine — use sparingly.
 */
function ollama_chat(array $messages, ?string $model = null): string
{
    $resolvedModel = ai_resolve_chat_model($model);
    $data = ollama_post('/api/chat', [
        'model'    => $resolvedModel,
        'messages' => $messages,
        'stream'   => false,
    ], AI_CHAT_TIMEOUT);

    return $data['message']['content'] ?? '';
}
