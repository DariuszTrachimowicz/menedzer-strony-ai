<?php

declare(strict_types=1);

namespace ASM\Operations;

use ASM\Storage\AuditStore;

final class OperationEngine
{
    private OperationRegistry $registry;

    /** @var object */
    private $store;
    private ?LockManager $locks;
    private ?AuditStore $audit;

    public function __construct(
        OperationRegistry $registry,
        object $store,
        ?LockManager $locks = null,
        ?AuditStore $audit = null
    ) {
        $this->registry = $registry;
        $this->store = $store;
        $this->locks = $locks;
        $this->audit = $audit;
    }

    public function preview(array $request, int $actorId, bool $guardedRollback = false): Operation
    {
        $action = (string) $request['action'];
        $handler = $this->registry->handler($action);
        if ($handler === null) {
            throw new \InvalidArgumentException('Unknown operation action: ' . $action);
        }

        $operationId = self::uuid4();
        $target = (array) $request['target'];
        $snapshotTarget = $target;
        $snapshotTarget['_operation_id'] = $operationId;
        $snapshot = method_exists($handler, 'snapshotWithChange')
            ? $handler->snapshotWithChange($snapshotTarget, (array) $request['change'])
            : $handler->snapshot($snapshotTarget);
        $preconditionHash = $guardedRollback ? self::guardedHash($snapshot, $target) : self::hash($snapshot);
        $snapshotSafe = false;
        if ($guardedRollback) {
            if (!self::jsonRoundtripSafe($request['change']) || !self::jsonRoundtripSafe($target)) {
                throw new OperationFailure('asm_unpersistable_change', 'Zmiana nie zachowuje typu lub wartości po zapisie JSON.');
            }
            $snapshotSafe = self::jsonRoundtripSafe($snapshot);
            // Keep the fingerprint of the actual state, but never persist a lossy
            // rollback snapshot (objects/binary strings cannot roundtrip this store).
            if (!$snapshotSafe) { $snapshot = []; }
        }
        $operation = new Operation(
            $operationId,
            $action,
            $target,
            (array) $request['change'],
            $snapshot,
            $preconditionHash,
            $actorId
        );

        if ($guardedRollback) { $operation->guardRollback(); $operation->setRollbackSnapshotSafe($snapshotSafe); }
        $expectedHash = isset($request['expected']['hash'])
            ? (string) $request['expected']['hash']
            : '';
        if ($expectedHash !== '' && ! hash_equals($expectedHash, $preconditionHash)) {
            $operation->setStatus('conflict', 'asm_conflict');
        }

        $this->store->save($operation);
        $this->audit('operation.previewed', $operation);
        return $operation;
    }

    public function execute(string $operationId, string $idempotencyKey): Operation
    {
        $existingId = $this->store->operationIdForKey($idempotencyKey);
        if ($existingId !== null) {
            return $this->requireOperation($existingId);
        }

        $operation = $this->requireOperation($operationId);
        if ($operation->status() !== 'previewed') {
            return $operation;
        }

        if (! $this->store->bindKey($idempotencyKey, $operationId)) {
            $operation->setStatus('failed', 'asm_idempotency_conflict');
            $this->store->save($operation);
            return $operation;
        }

        $lockKey = $this->lockKey($operation);
        if ($this->locks !== null && ! $this->locks->acquire($lockKey, $operation->id(), $this->lockTtl($operation))) {
            // Inne wykonanie mogło już zmienić stan: zapisujemy porażkę tylko na wciąż nietkniętym podglądzie.
            $fresh = $this->requireOperation($operationId);
            if ($fresh->status() !== 'previewed') {
                return $fresh;
            }
            $fresh->setStatus('failed', 'asm_resource_locked');
            // Zapis warunkowy: jeśli pierwsze wykonanie zdążyło zmienić status, zostaje jego wynik.
            $saved = method_exists($this->store, 'saveIfStatus')
                ? $this->store->saveIfStatus($fresh, 'previewed')
                : ($this->store->save($fresh) ?? true);
            if (! $saved) {
                return $this->requireOperation($operationId);
            }
            $this->audit('operation.lock_failed', $fresh);
            return $fresh;
        }

        try {
            // Drugie wywołanie tej samej operacji (inny klucz) czekało na blokadę: nie nadpisujemy wyniku pierwszego.
            $operation = $this->requireOperation($operationId);
            if ($operation->status() !== 'previewed') {
                return $operation;
            }
            $handler = $this->requireHandler($operation);
            $snapshotTarget = $operation->target();
            $snapshotTarget['_operation_id'] = $operation->id();
            $currentSnapshot = method_exists($handler, 'snapshotWithChange')
                ? $handler->snapshotWithChange($snapshotTarget, $operation->change())
                : $handler->snapshot($snapshotTarget);
            $currentHash = $operation->guardedRollback() ? self::guardedHash($currentSnapshot, $operation->target()) : self::hash($currentSnapshot);
            if (! hash_equals($operation->preconditionHash(), $currentHash)) {
                $operation->setStatus('conflict', 'asm_conflict');
                $this->store->save($operation);
                $this->audit('operation.conflict', $operation);
                return $operation;
            }

            $operation->setStatus('executing');
            $this->store->save($operation);
            $this->audit('operation.executing', $operation);

            try {
                if (method_exists($handler, 'applyWithSnapshot')) {
                    $applyResult = $handler->applyWithSnapshot(
                        $operation->target(),
                        $operation->change(),
                        $operation->snapshot()
                    );
                } else {
                    $applyResult = $handler->apply($operation->target(), $operation->change());
                }
                if (is_array($applyResult) && isset($applyResult['target']) && is_array($applyResult['target'])) {
                    $operation->setTarget(array_merge($operation->target(), $applyResult['target']));
                    $this->store->save($operation);
                }
                $verified = $handler->verify($operation->target(), $operation->change());
                if ($verified === true) {
                    $operation->setStatus('verified');
                    if ($operation->guardedRollback() && $operation->rollbackSnapshotSafe() && $this->canGuard($operation)) {
                        $operation->setAfterHash($this->stateHash($operation));
                    }
                } elseif ($verified === false) {
                    $operation->setStatus('failed', 'asm_verification_failed');
                } else {
                    $operation->setStatus('unknown', 'asm_unknown_state');
                }
            } catch (OperationFailure $error) {
                $operation->setStatus('failed', $error->operationCode());
            } catch (\Throwable $error) {
                $operation->setStatus('failed', 'asm_execution_failed');
            }

            $this->store->save($operation);
            $this->audit('operation.' . $operation->status(), $operation);
            return $operation;
        } finally {
            if ($this->locks !== null) {
                $this->locks->release($lockKey, $operation->id());
            }
        }
    }

    public function reconcile(string $operationId): Operation
    {
        $operation = $this->requireOperation($operationId);
        if ($operation->status() !== 'unknown') {
            return $operation;
        }

        $verified = $this->requireHandler($operation)->verify(
            $operation->target(),
            $operation->change()
        );
        if ($verified === true) {
            $operation->setStatus('verified');
        } elseif ($verified === false) {
            $operation->setStatus('failed', 'asm_verification_failed');
        }

        $this->store->save($operation);
        $this->audit('operation.reconciled', $operation);
        return $operation;
    }

    public function rollback(string $operationId): Operation
    {
        $operation = $this->requireOperation($operationId);
        if ($operation->guardedRollback()) {
            if (!$operation->rollbackSnapshotSafe() || !$this->canGuard($operation) || $operation->afterHash() === null || $operation->status() !== 'verified') {
                throw new OperationFailure('asm_rollback_unavailable', 'Brak mierzalnego stanu do automatycznego cofnięcia tej operacji.');
            }
            $lockKey = $this->lockKey($operation);
            if ($this->locks !== null && !$this->locks->acquire($lockKey, $operation->id(), $this->lockTtl($operation))) {
                throw new OperationFailure('asm_resource_locked', 'Zasób jest zablokowany.');
            }
            try {
                if (!hash_equals($operation->afterHash(), $this->stateHash($operation))) {
                    throw new OperationFailure('asm_conflict', 'Zasób zmienił się. Zachowano późniejszą zmianę.');
                }
                return $this->restore($operation);
            } finally {
                if ($this->locks !== null) { $this->locks->release($lockKey, $operation->id()); }
            }
        }
        // Operacja zatrzymana przed zmianą (blokada, klucz) nie ma czego cofać: przywrócenie kopii nadpisałoby późniejsze zmiany.
        if ($operation->status() === 'failed' && in_array($operation->errorCode(), array('asm_resource_locked', 'asm_idempotency_conflict'), true)) {
            throw new OperationFailure('asm_rollback_unavailable', 'Operacja nie została wykonana, więc nie ma czego cofać.');
        }
        $lockKey = $this->lockKey($operation);
        if ($this->locks !== null && ! $this->locks->acquire($lockKey, $operation->id(), $this->lockTtl($operation))) {
            throw new OperationFailure('asm_resource_locked', 'Zasób jest zablokowany.');
        }
        try {
            return $this->restore($this->requireOperation($operationId));
        } finally {
            if ($this->locks !== null) { $this->locks->release($lockKey, $operation->id()); }
        }
    }

    private function canGuard(Operation $operation): bool
    {
        return in_array($operation->action(), array('content.create','content.update','content.replace','content.meta','option.update','option.replace_secret','option.delete','file.create','file.write','file.patch'), true);
    }

    private function stateHash(Operation $operation): string
    {
        $target = $operation->target();
        $target['_operation_id'] = $operation->id() . '-after';
        if (($target['type'] ?? '') === 'posts') { clean_post_cache((int) ($target['id'] ?? 0)); }
        $snapshot=$this->requireHandler($operation)->snapshot($target);
        if (($target['type'] ?? '') === 'posts') {
            global $wpdb;
            $id=(int)($target['id']??0);
            $snapshot['related_comments']=$wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->comments WHERE comment_post_ID=%d ORDER BY comment_ID",$id),ARRAY_A);
            $snapshot['children']=$wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE post_parent=%d ORDER BY ID",$id),ARRAY_A);
            if ($wpdb->last_error) { throw new OperationFailure('asm_storage_failed','Nie można odczytać stanu powiązań wpisu.'); }
        }
        return self::guardedHash($snapshot, $target);
    }

    private function restore(Operation $operation): Operation
    {
        if (! in_array($operation->status(), array('verified', 'failed', 'unknown'), true)) {
            throw new OperationFailure(
                'asm_invalid_transition',
                'Niedozwolony stan operacji dla rollbacku.'
            );
        }
        $handler = $this->requireHandler($operation);
        $operation->setStatus('rolling_back');
        $this->store->save($operation);
        $dateGuard = null;
        if ($operation->guardedRollback() && isset($operation->snapshot()['post'])) {
            $post = $operation->snapshot()['post'];
            $dateGuard = static function ($fields, $postarr, $raw) use ($post) {
                if ((int) ($raw['ID'] ?? 0) === (int) $post['ID']) {
                    $fields['post_date'] = $post['post_date'];
                    $fields['post_date_gmt'] = $post['post_date_gmt'];
                }
                return $fields;
            };
            add_filter('wp_insert_post_data', $dateGuard, PHP_INT_MAX, 3);
        }

        try {
            $handler->rollback($operation->target(), $operation->snapshot());
            $restored = method_exists($handler, 'verifyRollback')
                ? $handler->verifyRollback($operation->target(), $operation->snapshot())
                : hash_equals(
                    $operation->preconditionHash(),
                    $operation->guardedRollback() ? self::guardedHash($handler->snapshot($operation->target()), $operation->target()) : self::hash($handler->snapshot($operation->target()))
                );
            if ($restored === true) {
                $operation->setStatus('rolled_back');
            } else {
                $operation->setStatus('rollback_failed', 'asm_rollback_failed');
            }
        } catch (\Throwable $error) {
            $operation->setStatus('rollback_failed', 'asm_rollback_failed');
        } finally {
            if ($dateGuard !== null) { remove_filter('wp_insert_post_data', $dateGuard, PHP_INT_MAX); }
        }

        $this->store->save($operation);
        $this->audit('operation.' . $operation->status(), $operation);
        return $operation;
    }

    public function get(string $operationId): Operation
    {
        return $this->requireOperation($operationId);
    }

    private function requireOperation(string $operationId): Operation
    {
        $operation = $this->store->get($operationId);
        if (! $operation instanceof Operation) {
            throw new \RuntimeException('Operation not found: ' . $operationId);
        }

        return $operation;
    }

    private function requireHandler(Operation $operation): object
    {
        $handler = $this->registry->handler($operation->action());
        if ($handler === null) {
            throw new \RuntimeException('Handler not found: ' . $operation->action());
        }

        return $handler;
    }

    // Aktualizacja wtyczki, motywu albo WordPressa trwa dłużej niż zwykła zmiana: blokada nie może wygasnąć w trakcie.
    private function lockTtl(Operation $operation): int
    {
        return preg_match('/^(plugin|theme|core)\./', $operation->action()) === 1 ? 900 : 120;
    }

    private function lockKey(Operation $operation): string
    {
        $target = $operation->target();
        $type = isset($target['type']) ? (string) $target['type'] : strtok($operation->action(), '.');
        $id = isset($target['id']) ? (string) $target['id'] : hash('sha256', json_encode($target));
        return $type . ':' . $id;
    }

    private function audit(string $event, Operation $operation): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->append(
            $operation->id(),
            $event,
            $operation->actorId(),
            array(
                'action' => $operation->action(),
                'target' => $operation->guardedRollback() ? array_intersect_key($operation->target(), array_flip(array('type','id','path','hook','timestamp','menu_id'))) : $operation->target(),
                'status' => $operation->status(),
                'error_code' => $operation->errorCode(),
            )
        );
    }

    /** Full-only fingerprint: serialize preserves scalar types, objects and bytes.
     * No deserialization is performed. Only file snapshot bookkeeping is volatile.
     */
    private static function guardedHash(array $snapshot, array $target): string
    {
        if (($target['type'] ?? '') === 'files') { unset($snapshot['backup_path']); }
        return hash('sha256', serialize(self::typedValue($snapshot)));
    }

    /** Encode object properties explicitly: never invoke custom __serialize hooks.
     * Cyclic/deeper-than-512 values and resources fail closed instead of colliding.
     */
    private static function typedValue($value, int $depth = 0)
    {
        if ($depth > 512 || is_resource($value)) {
            throw new OperationFailure('asm_unhashable_state', 'Stan zasobu nie ma obsługiwanej reprezentacji.');
        }
        if (is_array($value) || is_object($value)) {
            $items = [];
            foreach ((array) $value as $key => $item) {
                $items[] = [$key, self::typedValue($item, $depth + 1)];
            }
            return [is_object($value) ? ['object', get_class($value)] : ['array'], $items];
        }
        return [gettype($value), $value];
    }

    private static function jsonRoundtripSafe($value): bool
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            return self::typedValue($value) === self::typedValue(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            return false;
        }
    }

    /** @param mixed $value */
    private static function hash($value): string
    {
        return hash(
            'sha256',
            (string) json_encode(
                self::sortRecursively(self::withoutVolatileSnapshotFields($value)),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /** @param mixed $value
     *  @return mixed
     */
    private static function withoutVolatileSnapshotFields($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $filtered = array();
        foreach ($value as $key => $item) {
            if ($key === 'backup_path') {
                continue;
            }
            $filtered[$key] = self::withoutVolatileSnapshotFields($item);
        }
        return $filtered;
    }

    /** @param mixed $value
     *  @return mixed
     */
    private static function sortRecursively($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursively($item);
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
