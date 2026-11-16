<?php

declare(strict_types=1);

namespace ASM\Operations;

final class LockManager
{
    /** @var object */
    private $backend;

    public function __construct(object $backend)
    {
        $this->backend = $backend;
    }

    public function acquire(string $resourceKey, string $operationId, int $ttlSeconds = 120): bool
    {
        if ($resourceKey === '' || $operationId === '' || $ttlSeconds < 1) {
            return false;
        }

        return (bool) $this->backend->acquire(
            $resourceKey,
            $operationId,
            time() + $ttlSeconds
        );
    }

    public function release(string $resourceKey, string $operationId): bool
    {
        return (bool) $this->backend->release($resourceKey, $operationId);
    }
}
