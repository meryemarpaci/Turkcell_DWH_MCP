<?php

declare(strict_types=1);

namespace App\Mcp;

final class McpException extends \RuntimeException
{
    public mixed $data;

    public function __construct(int $code, string $message, mixed $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}
