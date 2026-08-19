<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Google Gemini generateContent via official REST:
 * POST .../v1beta/models/{model}:generateContent
 * Header: x-goog-api-key
 *
 * Gemini 3.x requires thoughtSignature on functionCall parts to be echoed
 * back exactly on subsequent turns (tool loop).
 */
final class GeminiEngine implements LlmEngine
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? (string) app_env('GEMINI_API_KEY', '');
        $this->model = $model ?? (string) app_env('GEMINI_MODEL', 'gemini-3-flash-preview');
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY missing');
        }
    }

    public function name(): string
    {
        return 'gemini:' . $this->model;
    }

    public function complete(array $messages, array $tools = []): array
    {
        $system = '';
        $contents = [];
        $pendingToolParts = [];

        $flushTools = static function () use (&$contents, &$pendingToolParts): void {
            if ($pendingToolParts === []) {
                return;
            }
            $contents[] = ['role' => 'user', 'parts' => $pendingToolParts];
            $pendingToolParts = [];
        };

        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                $system .= (($system === '') ? '' : "\n\n") . (string) ($m['content'] ?? '');
                continue;
            }
            if ($role === 'tool') {
                $decoded = json_decode((string) ($m['content'] ?? '{}'), true);
                $pendingToolParts[] = [
                    'functionResponse' => [
                        'name' => (string) ($m['name'] ?? 'tool'),
                        'response' => ['result' => is_array($decoded) ? $decoded : ['raw' => $m['content']]],
                    ],
                ];
                continue;
            }
            $flushTools();
            if ($role === 'assistant') {
                // Prefer exact model parts (preserves thoughtSignature for Gemini 3)
                if (!empty($m['gemini_parts']) && is_array($m['gemini_parts'])) {
                    $contents[] = ['role' => 'model', 'parts' => $m['gemini_parts']];
                    continue;
                }
                $contents[] = [
                    'role' => 'model',
                    'parts' => $this->buildModelPartsFromAssistant($m),
                ];
                continue;
            }
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ];
        }
        $flushTools();

        if ($contents === []) {
            throw new RuntimeException('Gemini API error: empty conversation contents');
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => max(1024, (int) app_env('GEMINI_MAX_OUTPUT_TOKENS', '4096')),
            ],
        ];
        if ($system !== '') {
            $body['systemInstruction'] = [
                'parts' => [['text' => $system]],
            ];
        }
        if ($tools !== []) {
            $decls = [];
            foreach ($tools as $t) {
                $fn = $t['function'] ?? $t;
                $params = $fn['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];
                $params = self::sanitizeGeminiSchema($params);
                if (($params['properties'] ?? null) === [] || !isset($params['properties'])) {
                    $params['properties'] = new \stdClass();
                }
                if (!isset($params['type'])) {
                    $params['type'] = 'object';
                }
                $decls[] = [
                    'name' => $fn['name'],
                    'description' => $fn['description'] ?? '',
                    'parameters' => $params,
                ];
            }
            $body['tools'] = [['functionDeclarations' => $decls]];
            $body['toolConfig'] = [
                'functionCallingConfig' => ['mode' => 'AUTO'],
            ];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model) . ':generateContent';

        $decoded = $this->postGenerateContent($url, $body);
        $candidate = $decoded['candidates'][0] ?? null;
        if ($candidate === null) {
            $block = $decoded['promptFeedback']['blockReason'] ?? 'empty candidates';
            throw new RuntimeException('Gemini API error: no candidates (' . $block . ')');
        }

        $parts = $candidate['content']['parts'] ?? [];
        $text = '';
        $toolCalls = [];
        $i = 0;
        foreach ($parts as $part) {
            if (isset($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                $args = $fc['args'] ?? [];
                if (is_object($args)) {
                    $args = (array) $args;
                }
                if (!is_array($args)) {
                    $args = [];
                }
                $sig = $part['thoughtSignature'] ?? $part['thought_signature'] ?? null;
                $toolCalls[] = [
                    'id' => 'call_' . (++$i),
                    'name' => (string) ($fc['name'] ?? ''),
                    'arguments' => $args,
                    'thought_signature' => is_string($sig) ? $sig : null,
                ];
            }
        }

        return [
            'content' => $text !== '' ? $text : null,
            'tool_calls' => $toolCalls,
            'gemini_parts' => $parts,
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function postGenerateContent(string $url, array $body): array
    {
        $attempts = max(1, (int) app_env('GEMINI_RETRY_ATTEMPTS', '2'));
        $lastMsg = 'unknown';
        for ($i = 1; $i <= $attempts; $i++) {
            $res = HttpJson::post($url, $body, [
                'x-goog-api-key: ' . $this->apiKey,
            ]);
            $decoded = $res['body'];
            if ($res['status'] < 400 && !isset($decoded['error'])) {
                return $decoded;
            }
            $lastMsg = (string) ($decoded['error']['message'] ?? ('HTTP ' . $res['status']));
            // Quota / hard 429: do not sleep-retry the same model — cascade or fall back.
            if (self::isQuotaError($lastMsg, $res['status'])) {
                throw new RuntimeException('Gemini API error: ' . $lastMsg);
            }
            if (!self::isTransientError($lastMsg, $res['status']) || $i === $attempts) {
                throw new RuntimeException('Gemini API error: ' . $lastMsg);
            }
            usleep(800_000 * $i);
        }
        throw new RuntimeException('Gemini API error: ' . $lastMsg);
    }

    public static function isQuotaError(string $message, int $status = 0): bool
    {
        $low = strtolower($message);
        if ($status === 429 && (str_contains($low, 'quota') || str_contains($low, 'rate'))) {
            return true;
        }
        return str_contains($low, 'quota exceeded')
            || str_contains($low, 'exceeded your current quota')
            || str_contains($low, 'rate-limits')
            || str_contains($low, 'generate_content_free_tier');
    }

    /** Parse "Please retry in 36.5s" → seconds to wait (or null). */
    public static function retryAfterSeconds(string $message): ?int
    {
        if (preg_match('/retry in\s+([0-9]+(?:\.[0-9]+)?)\s*s/i', $message, $m)) {
            return max(1, (int) ceil((float) $m[1]));
        }
        return null;
    }

    public static function isTransientError(string $message, int $status = 0): bool
    {
        if (self::isQuotaError($message, $status)) {
            return true;
        }
        $low = strtolower($message);
        if (in_array($status, [429, 500, 502, 503, 504], true)) {
            return true;
        }
        return str_contains($low, 'high demand')
            || str_contains($low, 'unavailable')
            || str_contains($low, 'try again')
            || str_contains($low, 'temporarily')
            || str_contains($low, 'resource_exhausted')
            || str_contains($low, 'rate limit')
            || str_contains($low, 'overloaded');
    }

    /** Keep cascading to the next Gemini model. */
    public static function shouldTryNextModel(string $message, int $status = 0): bool
    {
        if (self::isTransientError($message, $status)) {
            return true;
        }
        $low = strtolower($message);
        return str_contains($low, 'no longer available')
            || str_contains($low, 'not found')
            || str_contains($low, 'is not supported')
            || str_contains($low, 'invalid model');
    }

    /** @return list<string> */
    public static function modelCascade(): array
    {
        // Prefer Gemini 3.x IDs. Do NOT use gemini-2.5-flash / gemini-2.0-flash
        // (unavailable or limit:0 for many new free-tier keys).
        $primary = (string) app_env('GEMINI_MODEL', 'gemini-3-flash-preview');
        $raw = (string) app_env(
            'GEMINI_FALLBACK_MODELS',
            'gemini-3.1-flash-lite,gemini-flash-latest'
        );
        $models = [$primary];
        foreach (explode(',', $raw) as $m) {
            $m = trim($m);
            if ($m === '' || in_array($m, $models, true)) {
                continue;
            }
            // Hard-block retired / useless free-tier models for new keys
            if (preg_match('/^gemini-2\.(0|5)-flash$/i', $m)) {
                continue;
            }
            $models[] = $m;
        }
        return $models;
    }

    /** @param array<string,mixed> $m */
    private function buildModelPartsFromAssistant(array $m): array
    {
        $parts = [];
        if (!empty($m['content'])) {
            $parts[] = ['text' => (string) $m['content']];
        }
        $toolCalls = $m['tool_calls'] ?? [];
        if (!is_array($toolCalls)) {
            $toolCalls = [];
        }
        $firstFc = true;
        foreach ($toolCalls as $tc) {
            if (isset($tc['function'])) {
                $name = $tc['function']['name'] ?? '';
                $argsRaw = $tc['function']['arguments'] ?? '{}';
                $args = is_string($argsRaw) ? json_decode($argsRaw, true) : $argsRaw;
                $sig = $tc['thought_signature'] ?? $tc['thoughtSignature'] ?? $tc['function']['thought_signature'] ?? null;
            } else {
                $name = $tc['name'] ?? '';
                $args = $tc['arguments'] ?? [];
                $sig = $tc['thought_signature'] ?? $tc['thoughtSignature'] ?? null;
            }
            if (!is_array($args)) {
                $args = [];
            }
            $part = [
                'functionCall' => [
                    'name' => $name,
                    'args' => $args === [] ? new \stdClass() : $args,
                ],
            ];
            if (is_string($sig) && $sig !== '') {
                $part['thoughtSignature'] = $sig;
            } elseif ($firstFc) {
                // Last resort for history without signatures (e.g. transferred from Groq)
                $part['thoughtSignature'] = 'skip_thought_signature_validator';
            }
            $parts[] = $part;
            $firstFc = false;
        }
        return $parts !== [] ? $parts : [['text' => '']];
    }

    /**
     * Gemini functionDeclarations reject OpenAPI fields like additionalProperties.
     *
     * @param array<string,mixed>|\stdClass $schema
     * @return array<string,mixed>|\stdClass
     */
    private static function sanitizeGeminiSchema(mixed $schema): mixed
    {
        if ($schema instanceof \stdClass) {
            $schema = (array) $schema;
        }
        if (!is_array($schema)) {
            return $schema;
        }

        unset(
            $schema['additionalProperties'],
            $schema['$schema'],
            $schema['$id'],
            $schema['examples'],
            $schema['default'],
            $schema['const']
        );

        foreach (['properties', 'items', 'anyOf', 'oneOf', 'allOf'] as $key) {
            if (!isset($schema[$key])) {
                continue;
            }
            if ($key === 'properties' && is_array($schema[$key])) {
                if ($schema[$key] === []) {
                    $schema[$key] = new \stdClass();
                    continue;
                }
                $clean = [];
                foreach ($schema[$key] as $propName => $propSchema) {
                    $clean[$propName] = self::sanitizeGeminiSchema($propSchema);
                }
                $schema[$key] = $clean;
            } elseif ($key === 'items') {
                $schema[$key] = self::sanitizeGeminiSchema($schema[$key]);
            } elseif (is_array($schema[$key])) {
                $schema[$key] = array_map(
                    static fn ($s) => self::sanitizeGeminiSchema($s),
                    $schema[$key]
                );
            }
        }

        return $schema;
    }
}
