<?php
/**
 * AI model settings (onboarding). Optional: without them, the built-in Mentora rules engine
 * does every AI step (see ai/README.md).
 *
 * 1. Copy this file to ai/config.local.php (that copy holds your key, so don't share or commit it;
 *    it is listed in .gitignore).
 * 2. Fill in the values for the AI service or local model Mentora uses.
 *
 * Environment variables MENTORA_AI_PROVIDER / MENTORA_AI_API_KEY / MENTORA_AI_MODEL / MENTORA_AI_ENDPOINT
 * override these values if set.
 */
return [
    'provider' => '',   // anthropic, openai (or any OpenAI-compatible service), gemini, ollama; empty = rules engine only
    'api_key' => '',    // the service's secret key (leave empty for Ollama)
    'model' => '',      // the model name, e.g. claude-sonnet-5-5 (anthropic), or the Ollama model you pulled
    'endpoint' => '',   // the service's address; leave empty for the default (Ollama: http://localhost:11434)
];
