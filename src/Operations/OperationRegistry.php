<?php

declare(strict_types=1);

namespace ASM\Operations;

final class OperationRegistry
{
    /** @var array<string,object> */
    private array $handlers = array();

    public function register(string $action, object $handler): void
    {
        $this->handlers[$action] = $handler;
    }

    public function handler(string $action): ?object
    {
        return $this->handlers[$action] ?? null;
    }

    /** @return array<int,string> */
    public function actions(): array
    {
        return array_keys($this->handlers);
    }
}
