<?php

declare(strict_types=1);

namespace ASM\Operations;

final class Operation
{
    private string $id;
    private string $action;
    private array $target;
    private array $change;
    private array $snapshot;
    private string $preconditionHash;
    private int $actorId;
    private string $status;
    private ?string $errorCode = null;
    private bool $guardedRollback = false;
    private ?string $afterHash = null;
    private bool $rollbackSnapshotSafe = false;

    public function guardRollback(): void { $this->guardedRollback = true; }
    public function guardedRollback(): bool { return $this->guardedRollback; }
    public function afterHash(): ?string { return $this->afterHash; }
    public function setAfterHash(string $hash): void { $this->afterHash = $hash; }
    public function setRollbackSnapshotSafe(bool $safe): void { $this->rollbackSnapshotSafe = $safe; }
    public function rollbackSnapshotSafe(): bool { return $this->rollbackSnapshotSafe; }

    public function __construct(
        string $id,
        string $action,
        array $target,
        array $change,
        array $snapshot,
        string $preconditionHash,
        int $actorId,
        string $status = 'previewed'
    ) {
        $this->id = $id;
        $this->action = $action;
        $this->target = $target;
        $this->change = $change;
        $this->snapshot = $snapshot;
        $this->preconditionHash = $preconditionHash;
        $this->actorId = $actorId;
        $this->status = $status;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function target(): array
    {
        return $this->target;
    }

    /** @param array<string,mixed> $target */
    public function setTarget(array $target): void
    {
        $this->target = $target;
    }

    public function change(): array
    {
        return $this->change;
    }

    public function snapshot(): array
    {
        return $this->snapshot;
    }

    public function preconditionHash(): string
    {
        return $this->preconditionHash;
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function setStatus(string $status, ?string $errorCode = null): void
    {
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = array(
            'id' => $this->id,
            'action' => $this->action,
            'target' => $this->target,
            'change' => $this->change,
            'snapshot' => $this->snapshot,
            'precondition_hash' => $this->preconditionHash,
            'actor_id' => $this->actorId,
            'status' => $this->status,
            'error_code' => $this->errorCode,
            'guarded_rollback' => $this->guardedRollback,
            'after_hash' => $this->afterHash,
        );
        if ($this->guardedRollback) { $data['rollback_snapshot_safe'] = $this->rollbackSnapshotSafe; }
        return $data;
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $operation = new self(
            (string) $data['id'],
            (string) $data['action'],
            (array) $data['target'],
            (array) $data['change'],
            (array) $data['snapshot'],
            (string) $data['precondition_hash'],
            (int) $data['actor_id'],
            (string) $data['status']
        );
        $errorCode = isset($data['error_code']) && $data['error_code'] !== ''
            ? (string) $data['error_code']
            : null;
        $operation->setStatus((string) $data['status'], $errorCode);
        if (!empty($data['guarded_rollback'])) { $operation->guardRollback(); }
        if (isset($data['after_hash'])) { $operation->setAfterHash((string) $data['after_hash']); }
        $operation->setRollbackSnapshotSafe(!empty($data['rollback_snapshot_safe']));

        return $operation;
    }
}
