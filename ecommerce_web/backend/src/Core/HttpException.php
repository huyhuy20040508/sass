<?php

declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly int $status, string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
