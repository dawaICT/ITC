---
name: wuc-ai
description: >
  Add, extend, or debug AI-powered features in the WUC portal using the existing
  local Ollama infrastructure. Use whenever a task involves AI: building a new
  AI-assisted page, wiring wuc_ai_generate/ollama_chat, writing prompts, adding
  rate-limiting, embedding-based search, logging AI calls, or handling Ollama
  offline gracefully. Always fall back if Ollama is down — never block a page on AI.
---

# WUC Portal — AI Developer

## Architecture overview

The portal supports **two interchangeable AI backends** behind one entry point:

| Layer | Files | Purpose |
|-------|-------|---------|
| Local client | `ai/config.php`, `ai/ollama.php` | HTTP calls to localhost:11434 (Ollama) |
| Cloud client | `includes/ai_cloud.php` | OpenAI-compatible free cloud model (Groq, etc.) |
| High-level helpers | `includes/ai_portal.php` | Backend routing, rate-limiting, logging, fallbacks, `wuc_ai_generate()` |

**Entry point for all new AI features:** `wuc_ai_generate()` in `includes/ai_portal.php`.
It automatically picks the backend — you never call Ollama or the cloud client directly from a feature.

### Backend selection

`wuc_ai_local_status()` decides which backend is live, controlled by env var
`WUC_AI_BACKEND` (`auto` | `local` | `cloud`, default `auto`):

- **auto** (default): if a cloud API key is configured → use **cloud**; else if a
  local Ollama model is ready → use **local**; else → static fallback.
- **local**: prefer local Ollama, fall back to cloud if no local model.
- **cloud**: force the cloud backend.

Configuring a cloud key is an explicit opt-in to cloud and takes precedence over a
local server (this also stops a dev/mock local Ollama from shadowing real cloud AI).

### Cloud backend setup (free)

The cloud client (`includes/ai_cloud.php`) is OpenAI-compatible and ships with a
profile table. **Default provider is `pollinations` — free, keyless, no signup —
so AI works out of the box** (verified: `ai/cloud_test.php` returns a real reply).

Provider profiles (`wuc_ai_cloud_profiles()`):

| Provider | Key needed | Notes |
|----------|-----------|-------|
| `pollinations` (default) | No | Zero-config, free, keyless. Public service — use aggregates only. |
| `groq` | Yes (free) | Fast, more private/reliable. Key: <https://console.groq.com/keys> |
| `openrouter` | Yes (free) | Free models via `:free` suffix |
| `together` | Yes | Free trial credits |

To switch providers / add a key:
1. (Keyed) get a free key, put it in `ai/cloud_key.txt` (first non-comment line)
   or env `WUC_AI_CLOUD_API_KEY`. See `ai/cloud_key.txt.example`.
2. Set `WUC_AI_CLOUD_PROVIDER` (e.g. `groq`). Override model/endpoint with
   `WUC_AI_CLOUD_MODEL`, `WUC_AI_CLOUD_BASE_URL`, `WUC_AI_CLOUD_ENDPOINT`.
3. Verify: `C:\xampp\php\php.exe ai\cloud_test.php` → prints PASS and a sample reply.

**Privacy:** `pollinations` is a public keyless endpoint — only send aggregates
(totals, counts), never NRC/passport, payment references, or full records. For
production handling of student data, switch to `groq` (keyed) for a stronger
provider agreement.

**Provider failover chain:** `wuc_ai_cloud_chat()` tries an ordered chain and
uses the first provider that succeeds (`wuc_ai_cloud_chain()`):
- **A key is present** → chain = `[groq, pollinations]` (Groq preferred; keyless
  fallback if Groq errors/rate-limits).
- **No key** → chain = `[pollinations]` (free, keyless, zero-config).

Override with `WUC_AI_CLOUD_CHAIN="groq,pollinations"` or force one with
`WUC_AI_CLOUD_PROVIDER`.

**Rate limits / HTTP 429:** the keyless `pollinations` tier allows only ~1
concurrent request per IP and returns `429 Queue full` when requests overlap.
Each provider retries transient 429/5xx/transport errors with backoff
(`WUC_AI_CLOUD_RETRIES`, default 3; reduced to 2 when a fallback exists so it
fails over sooner). For reliable/multi-user load, add a free **Groq** key to
`ai/cloud_key.txt` — it then becomes the preferred provider automatically.

### `ai/mock_server.php`

A dev-only mock Ollama that returns **canned question-bank text** for any chat
call. Useful only for demoing the question bank offline. It does NOT produce real
answers and returns question-bank content even for unrelated features — do not rely
on it. When a cloud key is configured, the router bypasses it automatically.

```php
require_once dirname(__DIR__) . '/includes/ai_portal.php';
// or from a file inside students/ or lecturers/:
require_once dirname(__DIR__, 1) . '/../includes/ai_portal.php';
```

## Models

- **Embedding**: `nomic-embed-text` (768-dim) — fast, used by `ai/match.php` for skill matching
- **Chat**: `deepseek-r1:1.5b` default, falls back to `llama3.2:3b` then any installed chat model
- Model resolution is automatic via `ai_resolve_chat_model()` — never hard-code a model name in feature code

Check if AI is ready before any form:
```php
$aiStatus = wuc_ai_local_status();
// $aiStatus['model_ready'] === true  → AI available
// $aiStatus['model_ready'] === false → show fallback UI
```

## Building a new AI feature — checklist

### 1. Rate-limit every POST
```php
$rate = wuc_ai_rate_limit('my_feature_name', 10, 3600); // 10 calls/hour per session
if (!$rate['ok']) {
    $errors[] = 'Too many AI requests. Please try again in ' .
                max(1, (int)ceil($rate['retry_after'] / 60)) . ' minutes.';
}
```
Rate state lives in `$_SESSION['_ai_rate']`; no DB write needed.

### 2. Build a context array, serialize it, hash it
```php
$context = [
    'student_id'  => $studentId,
    'question'    => $userQuestion,   // already sanitized
    'data'        => $yourDataArray,  // only data this user is allowed to see
    'rules'       => ['do_not_invent_records' => true],
];
$contextJson = wuc_ai_context_json($context, 18000);  // safe JSON, truncated to ~18 KB
$contextHash = hash('sha256', $contextJson);
```

### 3. Build messages and call wuc_ai_generate
```php
$result = wuc_ai_generate($db, [
    'feature'       => 'my_feature_name',   // stored in ai_portal_logs
    'user_role'     => 'student',
    'user_id'       => $studentId,
    'input_summary' => substr($userQuestion, 0, 200),
    'context_hash'  => $contextHash,
    'messages'      => [
        ['role' => 'system', 'content' => 'You are the WUC portal assistant. ...'],
        ['role' => 'user',   'content' => "Context:\n{$contextJson}\n\nQuestion: {$userQuestion}"],
    ],
    'fallback' => static function () use ($context): string {
        return my_feature_fallback($context);  // always provide a text fallback
    },
]);
// $result['text']     → response string (AI or fallback)
// $result['used_ai']  → bool
// $result['status']   → 'ok'|'offline'|'model_missing'|'error'|'fallback'
```

### 4. Always provide a meaningful fallback function
If Ollama is offline the fallback runs. It must return a **useful text response** — not just "AI unavailable". Look at existing examples: `lecturer_insights_fallback()` in `lecturers/ai_progression_insights.php`, `student_study_fallback()` in `students/ai_study_assistant.php`.

### 5. Display correctly in HTML
AI replies are **Markdown** (headings, **bold**, tables, lists). Do NOT print them
raw with `white-space:pre-wrap` — that shows literal `###`/`**`/`| pipes |`. Use the
shared renderer `wuc_ai_output_block()` (from `includes/ai_markdown.php`, auto-loaded
by `ai_portal.php`). It HTML-escapes first (XSS-safe) then renders a styled subset.

```php
// Show AI vs fallback notice
if ($result['used_ai']) {
    echo '<div class="alert alert-info small">Generated by ' . h($result['model']) . '.</div>';
} else {
    echo '<div class="alert alert-warning small">' . h(wuc_ai_fallback_notice($result)) . '</div>';
}
// Render Markdown -> styled, safe HTML (the .ai-output stylesheet is injected once)
echo '<div class="bg-light border rounded p-3">' . wuc_ai_output_block($result['text']) . '</div>';
```

Exception: for output the user is meant to **edit** (e.g. the question bank, the
letter drafter) keep a `<textarea>` so they get the editable plain text.

## Conversational chat (multi-turn, with memory)

For a chatbot rather than a one-shot form, keep a rolling history in the session
and pass it as the `messages` array to `wuc_ai_generate()`. Reference pattern:
`students/ai_chat_ajax.php` + `students/ai_personal_assistant.php` (chat UI).

- Store history in `$_SESSION['ai_chat_history_'.md5($userId)]` as
  `[['role'=>'user'|'assistant','content'=>...], ...]`; cap to the last ~16
  messages so token size stays bounded.
- Each turn, rebuild `messages` = `[system instructions]` + `[system: fresh data
  context JSON]` + replayed history + the new user message. Re-inject the data
  context every turn so answers stay current; only the user/assistant text turns
  are persisted (not the big context blob).
- The AJAX endpoint returns `wuc_ai_render_markdown($reply)` wrapped in
  `<div class="ai-output">` (NOT `wuc_ai_output_block`, to avoid re-sending the
  `<style>` each turn) — emit `wuc_ai_output_styles()` once on the host page.
- Support a `reset` action to clear history ("New chat").

## Embedding-based search (skill matching)

For fuzzy/semantic search, use the embedding client directly:
```php
require_once dirname(__DIR__) . '/ai/ollama.php';
if (ollama_available()) {
    $queryVec = ollama_embed($userText);
    // Then compute cosine similarity against stored vectors — see ai/match.php
}
```
Cosine similarity helper (copy from `ai/match.php`):
```php
function cosine_similarity(array $a, array $b): float {
    $dot = $norm_a = $norm_b = 0.0;
    foreach ($a as $i => $v) { $dot += $v * $b[$i]; $norm_a += $v * $v; $norm_b += $b[$i] * $b[$i]; }
    return ($norm_a > 0 && $norm_b > 0) ? $dot / (sqrt($norm_a) * sqrt($norm_b)) : 0.0;
}
```

## Logging

`wuc_ai_generate()` calls `wuc_ai_log()` automatically. It also auto-creates the `ai_portal_logs` table if it doesn't exist. You do not need to call `wuc_ai_log()` directly unless you have a special action (e.g., `'action' => 'export'`).

## Prompt engineering tips for this portal

- **Ground the model**: pass the actual DB data as JSON in the user message; do not ask the model to recall facts.
- **Constrain output**: add explicit rules in the `context['rules']` dict and repeat them in the system prompt.
- **Strip reasoning**: `wuc_ai_strip_reasoning()` removes DeepSeek `<think>…</think>` blocks automatically inside `wuc_ai_generate`.
- **Chunking**: use `wuc_ai_context_json($data, 18000)` — this leaves room for the system prompt within the model's context window.
- **Sensitive data**: never pass financial reference numbers, NRC/passport numbers, or full payment records into AI context; pass aggregates instead.

## Existing AI features (don't duplicate)

| Feature | File | Scope |
|---------|------|-------|
| AI Study Assistant | `students/ai_study_assistant.php` | Course materials → summaries, quizzes |
| AI Course Advisor | `students/ai_course_advisor.php` | Course registration advice |
| AI Progression Insights | `lecturers/ai_progression_insights.php` | At-risk student analysis |
| AI Question Bank | `lecturers/ai_question_bank.php` | Auto-generate exam questions |
| AI Reports | `admin/ai_reports.php` | Admin-level analytics insights |
| Skill Matcher | `ai/match.php` | Embedding cosine similarity |

## Security rules for AI features

- Validate that the student/lecturer can only see their own data before passing it to AI.
- Never echo AI response as raw HTML — always escape with `htmlspecialchars()`.
- Always verify CSRF token on POST (call `wuc_verify_csrf()` or the manual hash_equals check from guard.php).
- Do not expose `ai_portal_logs` records to the user — they are for admin audit only.
