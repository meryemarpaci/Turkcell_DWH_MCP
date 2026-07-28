<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Groq OpenAI-compatible chat API (keys start with gsk_).
 * User may call it "Grok"; transport is Groq.
 */
final class GroqEngine implements LlmEngine
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? '';
        if ($this->apiKey === '') {
            $this->apiKey = (string) (app_env('GROQ_API_KEY') ?: app_env('GROK_API_KEY', ''));
        }
        $this->model = $model ?? (string) app_env('GROQ_MODEL', 'llama-3.3-70b-versatile');
        if ($this->apiKey === '') {
            throw new RuntimeException('GROQ_API_KEY / GROK_API_KEY missing');
        }
    }

    public function name(): string
    {
        return 'groq:' . $this->model;
    }

    public function complete(array $messages, array $tools = []): array
    {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 1024,
        ];
        if ($tools !== []) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
            $body['parallel_tool_calls'] = false;
        }

        $res = HttpJson::post($this->baseUrl, $body, [
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        $decoded = $res['body'];
        if ($res['status'] >= 400 || isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? ($decoded['error'] ?? ('HTTP ' . $res['status']));
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            $failed = (string) ($decoded['error']['failed_generation'] ?? '');
            $parsed = self::parseXmlToolCalls($failed);
            if ($parsed !== []) {
                return ['content' => null, 'tool_calls' => $parsed];
            }
            if ($failed !== '') {
                $msg .= ' | failed_generation=' . substr($failed, 0, 500);
            }
            throw new RuntimeException('Groq API error: ' . $msg);
        }

        $choice = $decoded['choices'][0]['message'] ?? [];
        $toolCalls = [];
        foreach ($choice['tool_calls'] ?? [] as $tc) {
            $argsRaw = $tc['function']['arguments'] ?? '{}';
            $args = is_string($argsRaw) ? json_decode($argsRaw, true) : $argsRaw;
            if (!is_array($args)) {
                $args = [];
            }
            $toolCalls[] = [
                'id' => (string) ($tc['id'] ?? uniqid('call_', true)),
                'name' => (string) ($tc['function']['name'] ?? ''),
                'arguments' => $args,
            ];
        }

        $content = isset($choice['content']) ? (string) $choice['content'] : '';
        if ($toolCalls === [] && $content !== '') {
            $parsed = self::parseXmlToolCalls($content);
            if ($parsed !== []) {
                return ['content' => null, 'tool_calls' => $parsed];
            }
        }

        return [
            'content' => $content !== '' ? $content : null,
            'tool_calls' => $toolCalls,
        ];
    }

    /** Parse Hermes/XML style: <function=name{json}></function> or <function=name {...} </function> */
    private static function parseXmlToolCalls(string $text): array
    {
        $out = [];
        if ($text === '') {
            return $out;
        }
        if (preg_match_all('/<function\s*=\s*([a-zA-Z0-9_]+)\s*(\{.*?\})\s*<\/function>/s', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $match) {
                $args = json_decode($match[2], true);
                $out[] = [
                    'id' => 'xml_call_' . ($i + 1),
                    'name' => $match[1],
                    'arguments' => is_array($args) ? $args : [],
                ];
            }
            return $out;
        }
        // variant without closing tag properly: <function=name {"a":1} </function>
        if (preg_match_all('/<function\s*=\s*([a-zA-Z0-9_]+)\s+(\{.*?\})\s*<\/function>/s', $text, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $i => $match) {
                $args = json_decode($match[2], true);
                $out[] = [
                    'id' => 'xml_call_' . ($i + 1),
                    'name' => $match[1],
                    'arguments' => is_array($args) ? $args : [],
                ];
            }
        }
        return $out;
    }
}
