<?php

declare(strict_types=1);

namespace ASM\Components;

final class DirectorySnapshot
{
    /** @return array<string,mixed> */
    public static function capture(string $directory, int $maxBytes = 268435456): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new \InvalidArgumentException('Nie znaleziono katalogu komponentu.');
        }
        $files = array();
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('Kopia komponentu nie obsługuje dowiązań symbolicznych.');
            }
            if (! $file->isFile()) {
                continue;
            }
            $size = $file->getSize();
            $total += $size;
            if ($total > $maxBytes) {
                throw new \RuntimeException('Komponent przekracza limit prywatnej kopii.');
            }
            $bytes = file_get_contents($file->getPathname());
            if (! is_string($bytes)) {
                throw new \RuntimeException('Nie można odczytać pliku komponentu.');
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            $files[$relative] = array(
                'bytes' => base64_encode($bytes),
                'sha256' => hash('sha256', $bytes),
                'mode' => $file->getPerms() & 0777,
            );
        }
        ksort($files);
        return array('files' => $files, 'total_bytes' => $total);
    }

    public static function restore(string $directory, array $snapshot): void
    {
        if (! isset($snapshot['files']) || ! is_array($snapshot['files'])) {
            throw new \InvalidArgumentException('Nieprawidłowa kopia katalogu.');
        }
        foreach (array_keys($snapshot['files']) as $relative) {
            if (! self::validRelative((string) $relative)) {
                throw new \InvalidArgumentException('Kopia zawiera niedozwoloną ścieżkę.');
            }
        }
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Nie można utworzyć katalogu komponentu.');
        }
        self::clear($directory);

        foreach ($snapshot['files'] as $relative => $file) {
            $bytes = base64_decode((string) ($file['bytes'] ?? ''), true);
            if (! is_string($bytes) || ! hash_equals((string) ($file['sha256'] ?? ''), hash('sha256', $bytes))) {
                throw new \RuntimeException('Kopia pliku komponentu jest uszkodzona.');
            }
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);
            $parent = dirname($path);
            if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new \RuntimeException('Nie można odtworzyć katalogu komponentu.');
            }
            if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('Nie można odtworzyć pliku komponentu.');
            }
            chmod($path, (int) ($file['mode'] ?? 0644));
        }
    }

    private static function validRelative(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return $path !== '' && $path[0] !== '/'
            && preg_match('/^[A-Za-z]:\//', $path) !== 1
            && preg_match('#(^|/)\.\.(/|$)#', $path) !== 1;
    }

    private static function clear(string $directory): void
    {
        foreach ((array) scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) && ! is_link($path)) {
                self::clear($path);
                if (! rmdir($path)) {
                    throw new \RuntimeException('Nie można usunąć starego katalogu komponentu.');
                }
            } elseif (! unlink($path)) {
                throw new \RuntimeException('Nie można usunąć starego pliku komponentu.');
            }
        }
    }
}
