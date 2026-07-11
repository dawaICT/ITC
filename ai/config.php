<?php
/**
 * AI module configuration for the local, offline skill-discovery engine.
 *
 * Everything here runs against a local Ollama server. Embeddings power the
 * skill matcher. Chat is optional and can be switched without editing code by
 * setting WUC_AI_CHAT_MODEL or AI_CHAT_MODEL in the environment.
 */

// Ollama server endpoint (local only).
define('OLLAMA_HOST', 'http://localhost:11434');

// Embedding model + its vector dimension. Must match what load_skills.php used.
define('AI_EMBED_MODEL', 'nomic-embed-text');
define('AI_EMBED_DIM', 768);

// Optional conversational model. Defaults to a small DeepSeek model that is
// practical on this local CPU setup. Override with WUC_AI_CHAT_MODEL or
// AI_CHAT_MODEL when another local Ollama model should be preferred.
$configuredChatModel = getenv('WUC_AI_CHAT_MODEL') ?: getenv('AI_CHAT_MODEL') ?: 'deepseek-r1:1.5b';
define('AI_CHAT_MODEL', $configuredChatModel);

// Ordered fallback preferences when the configured chat model is not installed.
define('AI_CHAT_MODEL_FALLBACKS', [
    'deepseek-r1:1.5b',
    'llama3.2:3b',
]);

// HTTP timeouts (seconds). Embeddings are quick; chat on this CPU can be slow.
define('AI_EMBED_TIMEOUT', 30);
define('AI_CHAT_TIMEOUT', 180);
