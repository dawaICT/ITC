<?php
class TurnitinClient {
    private array $config;
    public function __construct() { $this->config = require __DIR__ . '/../config/elearning.php'; }
    public function isEnabled(): bool { return !empty($this->config['turnitin']['enabled']); }
    public function submitDocument(string $absolutePath, string $title, string $authorSid): array {
        if (!$this->isEnabled()) { return ['id' => null, 'similarity' => null]; }
        // Stub: Integrate with Turnitin REST APIs (OAuth2) to submit and fetch similarity
        // For now, return a fake id and null similarity
        return ['id' => 'TII-' . md5($absolutePath . microtime(true)), 'similarity' => null];
    }
}


