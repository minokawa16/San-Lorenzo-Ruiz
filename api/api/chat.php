<?php
/**
 * Backward-compatible chat endpoint.
 *
 * The production chatbot logic lives in ../ai-assistant.php so the Ollama
 * integration, security checks, logging, and fallback behavior stay centralized.
 */

define('TUGON_AI_ALLOW_GUEST', true);
require_once __DIR__ . '/../ai-assistant.php';
?>
