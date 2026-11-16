<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Filesystem\PathPolicy;
use ASM\Operations\OperationFailure;
use ASM\Storage\PrivateStorage;

final class FileResource
{
    private string $mode;
    private PathPolicy $paths;
    private PrivateStorage $storage;

    public function __construct(string $mode, PathPolicy $paths, PrivateStorage $storage)
    {
        $this->mode = $mode;
        $this->paths = $paths;
        $this->storage = $storage;
    }

    public function list(array $params = array())
    {
        $path = (string) ($params['path'] ?? '.');
        $resolved = $this->paths->resolve($path, true);
        if ($resolved instanceof \WP_Error) {
            return $resolved;
        }
        if (! is_dir($resolved)) {
            return new \WP_Error('asm_not_a_directory', 'Wskazana ścieżka nie jest katalogiem.', array('status' => 400));
        }
        $items = array();
        foreach ((array) scandir($resolved) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $resolved . DIRECTORY_SEPARATOR . $name;
            $items[] = array(
                'id' => $this->relative($child),
                'name' => $name,
                'type' => is_dir($child) ? 'directory' : 'file',
                'size' => is_file($child) ? filesize($child) : null,
                'modified' => filemtime($child),
                'writable' => is_writable($child),
                'symlink' => is_link($child),
            );
        }
        return $items;
    }

    public function get(string $id)
    {
        $resolved = $this->paths->resolve(rawurldecode($id), true);
        if ($resolved instanceof \WP_Error) {
            return $resolved;
        }
        if (! is_file($resolved) || filesize($resolved) > 2097152) {
            return new \WP_Error('asm_file_not_readable', 'Plik nie istnieje albo przekracza limit odczytu.', array('status' => 400));
        }
        $content = file_get_contents($resolved);
        if (! is_string($content) || strpos($content, "\0") !== false) {
            return new \WP_Error('asm_binary_file', 'Odczyt binarnych plików przez ten endpoint jest zablokowany.', array('status' => 415));
        }
        return array(
            'id' => $this->relative($resolved),
            'content' => $this->redactConfig($resolved, $content),
            'redacted' => $this->redactConfig($resolved, $content) !== $content,
            'sha256' => hash('sha256', $content),
            'size' => strlen($content),
            'modified' => filemtime($resolved),
        );
    }

    public function snapshot(array $target): array
    {
        $path = (string) ($target['path'] ?? $target['id'] ?? '');
        $resolved = $this->paths->resolve($path, $this->mode !== 'create');
        if ($resolved instanceof \WP_Error) {
            if ($this->mode === 'create' && $resolved->get_error_code() === 'asm_path_not_found') {
                return array('exists' => false, 'path' => $path);
            }
            throw new OperationFailure($resolved->get_error_code(), $resolved->get_error_message());
        }
        if (! file_exists($resolved)) {
            return array('exists' => false, 'path' => $resolved);
        }
        if (! is_file($resolved)) {
            throw new OperationFailure('asm_not_a_file', 'Operacja obsługuje wyłącznie pliki.');
        }
        $bytes = file_get_contents($resolved);
        if (! is_string($bytes)) {
            throw new OperationFailure('asm_file_unreadable', 'Nie można odczytać pliku.');
        }
        $operationId = (string) ($target['_operation_id'] ?? 'snapshot-' . bin2hex(random_bytes(8)));
        $backup = $this->storage->store($operationId, 'files/' . hash('sha256', $resolved) . '-' . basename($resolved), $bytes);
        return array(
            'exists' => true,
            'path' => $resolved,
            'backup_path' => $backup,
            'sha256' => hash('sha256', $bytes),
            'permissions' => fileperms($resolved) & 0777,
            'size' => strlen($bytes),
        );
    }

    public function snapshotWithChange(array $target, array $change): array
    {
        $snapshot = $this->snapshot($target);
        if ($this->mode === 'move') {
            $snapshot['destination'] = (string) ($change['destination'] ?? '');
        }
        return $snapshot;
    }

    public function apply(array $target, array $change): void
    {
        $this->applyWithSnapshot($target, $change, array());
    }

    public function applyWithSnapshot(array $target, array $change, array $snapshot): void
    {
        $path = (string) ($target['path'] ?? $target['id'] ?? '');
        $resolved = $this->paths->resolve($path, ! in_array($this->mode, array('create'), true));
        if ($resolved instanceof \WP_Error) {
            throw new OperationFailure($resolved->get_error_code(), $resolved->get_error_message());
        }
        if ($this->mode === 'delete') {
            if (! unlink($resolved)) {
                throw new OperationFailure('asm_file_delete_failed', 'Nie udało się przenieść pliku do kopii prywatnej.');
            }
            return;
        }
        if ($this->mode === 'move') {
            $destination = $this->paths->resolve((string) ($change['destination'] ?? ''), false);
            if ($destination instanceof \WP_Error || file_exists($destination)) {
                throw new OperationFailure('asm_file_move_target_invalid', 'Docelowa ścieżka jest nieprawidłowa lub zajęta.');
            }
            if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0755, true)) {
                throw new OperationFailure('asm_file_directory_failed', 'Nie udało się utworzyć katalogu docelowego.');
            }
            if (! rename($resolved, $destination)) {
                throw new OperationFailure('asm_file_move_failed', 'Nie udało się przenieść pliku.');
            }
            return;
        }

        $content = (string) ($change['content'] ?? '');
        if ($this->mode === 'patch') {
            $current = file_get_contents($resolved);
            if (! is_string($current)) {
                throw new OperationFailure('asm_file_unreadable', 'Nie można odczytać pliku do patcha.');
            }
            $patched = ExactReplacement::apply(
                $current,
                (string) ($change['old'] ?? ''),
                (string) ($change['new'] ?? ''),
                (int) ($change['expected_occurrences'] ?? 1)
            );
            if ($patched instanceof \WP_Error) {
                throw new OperationFailure($patched->get_error_code(), $patched->get_error_message());
            }
            $content = $patched;
        }
        $this->atomicWrite($resolved, $content, (int) ($snapshot['permissions'] ?? 0644));
    }

    public function verify(array $target, array $change): ?bool
    {
        $path = (string) ($target['path'] ?? $target['id'] ?? '');
        if ($this->mode === 'move') {
            $destination = $this->paths->resolve((string) ($change['destination'] ?? ''), true);
            return ! ($destination instanceof \WP_Error) && is_file($destination);
        }
        $resolved = $this->paths->resolve($path, $this->mode !== 'delete');
        if ($this->mode === 'delete') {
            return $resolved instanceof \WP_Error && $resolved->get_error_code() === 'asm_path_not_found';
        }
        if ($resolved instanceof \WP_Error || ! is_file($resolved)) {
            return false;
        }
        $content = file_get_contents($resolved);
        if ($this->mode === 'patch') {
            return is_string($content) && substr_count($content, (string) ($change['new'] ?? ''))
                >= (int) ($change['expected_occurrences'] ?? 1);
        }
        return is_string($content) && hash_equals(hash('sha256', (string) ($change['content'] ?? '')), hash('sha256', $content));
    }

    public function rollback(array $target, array $snapshot): void
    {
        if (empty($snapshot['exists'])) {
            $resolved = $this->paths->resolve((string) ($target['path'] ?? ''), true);
            if (! ($resolved instanceof \WP_Error) && is_file($resolved)) {
                unlink($resolved);
            }
            return;
        }
        $destination = (string) $snapshot['path'];
        if ($this->mode === 'move') {
            $moved = $this->paths->resolve((string) ($snapshot['destination'] ?? ''), true);
            if (! ($moved instanceof \WP_Error) && is_file($moved)) {
                unlink($moved);
            }
        }
        $bytes = file_get_contents((string) $snapshot['backup_path']);
        if (! is_string($bytes)) {
            throw new OperationFailure('asm_file_backup_missing', 'Brakuje prywatnej kopii pliku.');
        }
        $this->atomicWrite($destination, $bytes, (int) $snapshot['permissions']);
    }

    public function verifyRollback(array $target, array $snapshot): bool
    {
        if (empty($snapshot['exists'])) {
            $resolved = $this->paths->resolve((string) ($target['path'] ?? $target['id'] ?? ''), true);
            return $resolved instanceof \WP_Error && $resolved->get_error_code() === 'asm_path_not_found';
        }

        $path = (string) ($snapshot['path'] ?? '');
        if (! is_file($path) || ! hash_equals((string) $snapshot['sha256'], (string) hash_file('sha256', $path))) {
            return false;
        }
        if ($this->mode !== 'move') {
            return true;
        }

        $destination = $this->paths->resolve((string) ($snapshot['destination'] ?? ''), true);
        return $destination instanceof \WP_Error && $destination->get_error_code() === 'asm_path_not_found';
    }

    private function atomicWrite(string $path, string $content, int $permissions): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new OperationFailure('asm_file_directory_failed', 'Nie udało się utworzyć katalogu.');
        }
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            try {
                token_get_all($content, TOKEN_PARSE);
            } catch (\ParseError $error) {
                throw new OperationFailure('asm_php_syntax_error', $error->getMessage());
            }
        }
        $temporary = tempnam($directory, '.asm-write-');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            throw new OperationFailure('asm_file_write_failed', 'Nie udało się zapisać pliku tymczasowego.');
        }
        chmod($temporary, $permissions > 0 ? $permissions : 0644);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new OperationFailure('asm_file_write_failed', 'Nie udało się atomowo zatwierdzić pliku.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', ABSPATH), '/');
        $path = str_replace('\\', '/', $path);
        return strpos($path, $root . '/') === 0 ? substr($path, strlen($root) + 1) : $path;
    }

    private function redactConfig(string $path, string $content): string
    {
        $name = strtolower(basename($path));
        if (! in_array($name, array('wp-config.php', '.env', '.user.ini'), true)) {
            return $content;
        }
        $content = preg_replace(
            '/(define\s*\(\s*[\'\"](?:DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)[\'\"]\s*,\s*)[\'\"][^\'\"]*[\'\"](\s*\))/i',
            '$1\'[REDACTED]\'$2',
            $content
        );
        return (string) preg_replace('/(^\s*[^#=]*(?:PASS|SECRET|TOKEN|KEY)[^=]*=).+$/mi', '$1[REDACTED]', (string) $content);
    }
}
