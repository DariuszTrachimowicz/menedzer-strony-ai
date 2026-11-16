<?php

declare(strict_types=1);

namespace ASM\Operations;

final class OperationFailure extends \RuntimeException
{
    private string $operationCode;

    public function __construct(string $operationCode, string $message)
    {
        parent::__construct($message);
        $this->operationCode = $operationCode;
    }

    public function operationCode(): string
    {
        return $this->operationCode;
    }
}
