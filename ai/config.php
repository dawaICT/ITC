<?php
/**
 * AI module configuration for the local, offline skill-discovery engine.
 *
 * Everything here runs against a local Ollama server. Embeddings power the
 * skill matcher. Chat is optional and can be switched without editing code by
 * setting WUC_AI_CHAT_MODEL or AI_CHAT_MODEL in the environment.
 */

// Ollama server endpoint (local only). Override with OLLAMA_HOST or WUC_OLLAMA_HOST.
$ollamaHost = getenv('WUC_OLLAMA_HOST') ?: getenv('OLLAMA_HOST') ?: 'http://127.0.0.1:11434';
define('OLLAMA_HOST', rtrim((string)$ollamaHost, '/'));

// Embedding model + its vector dimension. Must match what load_skills.php used.
define('AI_EMBED_MODEL', getenv('WUC_AI_EMBED_MODEL') ?: getenv('AI_EMBED_MODEL') ?: 'nomic-embed-text');
define('AI_EMBED_DIM', 768);

// Optional conversational model. Defaults to a small local model practical on
// CPU. Override with WUC_AI_CHAT_MODEL or AI_CHAT_MODEL.
$configuredChatModel = getenv('WUC_AI_CHAT_MODEL') ?: getenv('AI_CHAT_MODEL') ?: 'llama3.2:3b';
define('AI_CHAT_MODEL', $configuredChatModel);

// Ordered fallback preferences when the configured chat model is not installed.
define('AI_CHAT_MODEL_FALLBACKS', [
    'llama3.2:3b',
    'deepseek-r1:1.5b',
    'llama3.2',
    'phi3:mini',
]);

// HTTP timeouts (seconds). Embeddings are quick; chat on this CPU can be slow.
define('AI_EMBED_TIMEOUT', 30);
define('AI_CHAT_TIMEOUT', 180);
