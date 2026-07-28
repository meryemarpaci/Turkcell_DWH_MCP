<?php

declare(strict_types=1);

namespace App;

/**
 * Normalized chat completion with optional tool calls.
 */
interface LlmEngine
{
    public function name(): string;

    /**
     * @param list<array<string,mixed>> $messages OpenAI-style messages
     * @param list<array<string,mixed>> $tools OpenAI tools[] (type=function)
     * @return array{
     *   content: ?string,
     *   tool_calls: list<array{id:string,name:string,arguments:array}>
     * }
     */
    public function complete(array $messages, array $tools = []): array;
}
