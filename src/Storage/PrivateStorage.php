<?php

declare(strict_types=1);

namespace ASM\Storage;

final class PrivateStorage
{
    private string $root;
    private string $documentRoot;

    public function __construct(string $root, string $documentRoot)
    {
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new \RuntimeException('Nie można utworzyć prywatnego katalogu ASM.');
        }

        $resolvedRoot = realpath($root);
        $resolvedDocumentRoot = realpath($documentRoot);
        if ($resolvedRoot === false || $resolvedDocumentRoot === false) {
            throw new \RuntimeException('Nie można ustalić bezpiecznej ścieżki przechowywania.');
        }

        $rootPrefix = rtrim($this->normalize($resolvedRoot), '/') . '/';
        $documentPrefix = rtrim($this->normalize($resolvedDocumentRoot), '/') . '/';
        if (strpos($rootPrefix, $documentPrefix) === 0) {
            throw new \RuntimeException('ASM_PRIVATE_STORAGE musi znajdować się poza katalogiem publicznym.');
        }

        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
        $this->documentRoot = rtrim($resolvedDocumentRoot, DIRECTORY_SEPARATOR);
    }

    public function store(string $operationId, string $relativeName, string $bytes): string
    {
        $relativeName = $this->validateRelative($operationId, $relativeName);

        $relativeName = ltrim($relativeName, './');
        $directory = $this->root . DIRECTORY_SEPARATOR . $operationId . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, dirname($relativeName));
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Nie można utworzyć katalogu kopii.');
        }

        $target = $directory . DIRECTORY_SEPARATOR . basename($relativeName);
        $temporary = tempnam($directory, '.asm-');
        if ($temporary === false) {
            throw new \RuntimeException('Nie można utworzyć pliku tymczasowego.');
        }

        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new \RuntimeException('Nie udało się zapisać pełnej kopii.');
            }
            @chmod($temporary, 0600);
            if (! rename($temporary, $target)) {
                throw new \RuntimeException('Nie udało się zatwierdzić kopii atomowo.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        @chmod($target, 0600);
        $resolved = realpath($target);
        if ($resolved === false) {
            throw new \RuntimeException('Nie można odczytać zapisanej kopii.');
        }

        return $resolved;
    }

    public function path(string $operationId, string $relativeName): string
    {
        $relativeName = $this->validateRelative($operationId, $relativeName);
        $candidate = $this->root . DIRECTORY_SEPARATOR . $operationId . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeName);
        $resolved = realpath($candidate);
        $rootPrefix = rtrim($this->normalize($this->root), '/') . '/';
        if ($resolved === false || strpos($this->normalize($resolved), $rootPrefix) !== 0 || ! is_file($resolved)) {
            throw new \RuntimeException('Nie znaleziono prywatnego pliku ASM.');
        }
        return $resolved;
    }

    /** @return array<string,int> Katalogi kopii (operacje i wgrane pliki) z czasem ostatniej zmiany. */
    public function directories(): array
    {
        $items = array();
        foreach ((array) scandir($this->root) as $name) {
            $name = (string) $name;
            $path = $this->root . DIRECTORY_SEPARATOR . $name;
            if ($name !== '.' && $name !== '..' && preg_match('/^[A-Za-z0-9._-]+$/', $name) && is_dir($path) && ! is_link($path)) {
                $items[$name] = (int) filemtime($path);
            }
        }
        return $items;
    }

    /** Usuwa katalog kopii jednej operacji. Dowiązań nie śledzi, więc nie wyjdzie poza magazyn. */
    public function remove(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name) || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Nieprawidłowy katalog kopii.');
        }
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        if (! is_dir($path) || is_link($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    private function validateRelative(string $operationId, string $relativeName): string
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $operationId)) {
            throw new \InvalidArgumentException('Nieprawidłowy identyfikator operacji.');
        }

        $relativeName = str_replace('\\', '/', $relativeName);
        if ($relativeName === '' || $relativeName[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $relativeName)) {
            throw new \InvalidArgumentException('Nieprawidłowa ścieżka kopii.');
        }
        return ltrim($relativeName, './');
    }

    private function normalize(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }
}
