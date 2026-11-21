<?php

/**
 * Mentora AI engine: settings and the (optional) call to an AI model.
 *
 * Every AI feature works in two layers:
 *   1. The built-in Mentora rules engine (always available, no key needed): skill matching, scoring and
 *      template summaries, in ai/skills.php, ai/resume_review.php, ai/intern_assessment.php,
 *      ai/report_summary.php.
 *   2. An AI model, when one is set in ai/config.local.php: it writes the summaries and judgments
 *      (JSON), and the rules engine checks and completes its answer. If the model can't be reached,
 *      the rules engine's result is used, so onboarding never stops.
 */

declare(strict_types=1);

const MENTORA_AI_PROVIDERS = ['anthropic', 'openai', 'gemini', 'ollama'];
const MENTORA_AI_RULES_ENGINE = 'Mentora rules engine';

/** Settings from ai/config.local.php, overridden by MENTORA_AI_* environment variables. */
function mentora_ai_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $file = __DIR__ . '/config.local.php';
    $loaded = is_file($file) ? require $file : [];
    $config = [
        'provider' => strtolower(trim((string) ($loaded['provider'] ?? ''))),
        'api_key' => (string) ($loaded['api_key'] ?? ''),
        'model' => trim((string) ($loaded['model'] ?? '')),
        'endpoint' => rtrim(trim((string) ($loaded['endpoint'] ?? '')), '/'),
    ];
    foreach (['provider' => 'MENTORA_AI_PROVIDER', 'api_key' => 'MENTORA_AI_API_KEY', 'model' => 'MENTORA_AI_MODEL', 'endpoint' => 'MENTORA_AI_ENDPOINT'] as $key => $env) {
        $value = getenv($env);
        if (is_string($value) && $value !== '') {
            $config[$key] = $key === 'provider' ? strtolower($value) : ($key === 'endpoint' ? rtrim($value, '/') : $value);
        }
    }

    return $config;
}

/** True when an AI model is set up (otherwise only the rules engine runs). */
function mentora_ai_model_enabled(): bool
{
    $config = mentora_ai_config();
    if (!in_array($config['provider'], MENTORA_AI_PROVIDERS, true) || $config['model'] === '' || !function_exists('curl_init')) {
        return false;
    }

    return $config['provider'] === 'ollama' || $config['api_key'] !== '';
}

/** Name shown next to AI results, e.g. "anthropic · claude-sonnet-5-5" or "Mentora rules engine". */
function mentora_ai_model_label(): string
{
    $config = mentora_ai_config();

    return mentora_ai_model_enabled() ? $config['provider'] . ' · ' . $config['model'] : MENTORA_AI_RULES_ENGINE;
}

/**
 * Ask the AI model for a JSON object. $system holds the instructions; $prompt holds the data
 * (applicant text etc., which the model is told to treat as data, never as instructions).
 * Returns the decoded object, or null when no model is set up or the answer wasn't usable.
 */
function mentora_ai_json(string $system, string $prompt, int $maxTokens = 1200): ?array
{
    if (!mentora_ai_model_enabled()) {
        return null;
    }
    $config = mentora_ai_config();
    $system .= "\nReply with one JSON object only, no other text.";

    [$url, $headers, $body] = match ($config['provider']) {
        'anthropic' => [
            ($config['endpoint'] ?: 'https://api.anthropic.com') . '/v1/messages',
            ['x-api-key: ' . $config['api_key'], 'anthropic-version: 2023-06-01'],
            ['model' => $config['model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $prompt]]],
        ],
        'openai' => [
            ($config['endpoint'] ?: 'https://api.openai.com') . '/v1/chat/completions',
            ['Authorization: Bearer ' . $config['api_key']],
            ['model' => $config['model'], 'max_tokens' => $maxTokens, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]],
        ],
        'gemini' => [
            ($config['endpoint'] ?: 'https://generativelanguage.googleapis.com') . '/v1beta/models/' . rawurlencode($config['model']) . ':generateContent',
            ['x-goog-api-key: ' . $config['api_key']],
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json'],
            ],
        ],
        default => [ // ollama
            ($config['endpoint'] ?: 'http://localhost:11434') . '/api/chat',
            [],
            ['model' => $config['model'], 'stream' => false, 'format' => 'json', 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]],
        ],
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if (!is_string($raw) || $status >= 400 || $status === 0) {
        // Never log the key; the reply body helps diagnose a wrong model name or quota.
        error_log(sprintf('Mentora AI: %s request failed (HTTP %d) %s %s', $config['provider'], $status, $error, is_string($raw) ? mb_substr($raw, 0, 300) : ''));
        return null;
    }

    $reply = json_decode($raw, true);
    $text = match ($config['provider']) {
        'anthropic' => $reply['content'][0]['text'] ?? null,
        'openai' => $reply['choices'][0]['message']['content'] ?? null,
        'gemini' => $reply['candidates'][0]['content']['parts'][0]['text'] ?? null,
        default => $reply['message']['content'] ?? null,
    };

    return is_string($text) ? mentora_ai_decode_object($text) : null;
}

/** The first {...} object in a model's reply (models sometimes wrap JSON in prose or ``` fences). */
function mentora_ai_decode_object(string $text): ?array
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $data = json_decode(substr($text, $start, $end - $start + 1), true);

    return is_array($data) ? $data : null;
}

/** A string from a model answer, trimmed and length-limited ('' if missing). */
function mentora_ai_text(mixed $value, int $max = 2000): string
{
    return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
}

/** A list of short strings from a model answer. */
function mentora_ai_list(mixed $value, int $maxItems = 8, int $maxLength = 200): array
{
    if (!is_array($value)) {
        return [];
    }
    $items = [];
    foreach ($value as $item) {
        if (is_string($item) && trim($item) !== '') {
            $items[] = mb_substr(trim($item), 0, $maxLength);
        }
    }

    return array_slice(array_values(array_unique($items)), 0, $maxItems);
}

/** JSON for a TEXT column (lists of skills, metrics). */
function mentora_ai_encode(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';
}

/** Decode a JSON TEXT column back to an array ([] when empty or invalid). */
function mentora_ai_decode(?string $value): array
{
    $data = $value ? json_decode($value, true) : null;

    return is_array($data) ? $data : [];
}
