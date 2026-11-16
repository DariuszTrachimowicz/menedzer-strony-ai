<?php

declare(strict_types=1);

namespace ASM\Filesystem;

final class PathPolicy
{
    /** @var array<int,string> */
    private array $roots;

    /** @param array<int,string> $roots */
    public function __construct(array $roots)
    {
        $this->roots = array();
        foreach ($roots as $root) {
            $resolved = realpath($root);
            if ($resolved !== false && is_dir($resolved)) {
                $this->roots[] = rtrim($resolved, DIRECTORY_SEPARATOR);
            }
        }
        if ($this->roots === array()) {
            throw new \InvalidArgumentException('Brak prawidłowego katalogu dozwolonego.');
        }
    }

    /** @return string|\WP_Error */
    public function resolve(string $path, bool $mustExist = true)
    {
        if ($path === '' || strpos(str_replace('\\', '/', $path), "\0") !== false) {
            return $this->forbidden();
        }
        $normalizedInput = str_replace('\\', '/', $path);
        if (preg_match('#(^|/)\.\.(/|$)#', $normalizedInput)) {
            return $this->forbidden();
        }

        $absolute = $this->isAbsolute($path)
            ? $path
            : $this->roots[0] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $resolved = realpath($absolute);
        if ($resolved === false) {
            if ($mustExist) {
                return new \WP_Error('asm_path_not_found', 'Nie znaleziono wskazanej ścieżki.', array('status' => 404));
            }
            $resolved = $this->resolveFuturePath($absolute);
            if ($resolved === null) {
                return $this->forbidden();
            }
        }

        foreach ($this->roots as $root) {
            if ($this->isWithin($resolved, $root)) {
                return $resolved;
            }
        }
        return $this->forbidden();
    }

    private function resolveFuturePath(string $path): ?string
    {
        $remaining = array();
        $cursor = $path;
        while (! file_exists($cursor)) {
            $name = basename($cursor);
            if ($name === '' || $name === '.' || $name === '..') {
                return null;
            }
            array_unshift($remaining, $name);
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                return null;
            }
            $cursor = $parent;
        }
        $existing = realpath($cursor);
        if ($existing === false) {
            return null;
        }
        return $existing . ($remaining === array() ? '' : DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $remaining));
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = $this->normalize($path);
        $root = rtrim($this->normalize($root), '/') . '/';
        return $path === rtrim($root, '/') || strpos($path . '/', $root) === 0;
    }

    private function isAbsolute(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function forbidden(): \WP_Error
    {
        return new \WP_Error(
            'asm_path_forbidden',
            'Ścieżka wychodzi poza dozwolone katalogi WordPressa.',
            array('status' => 403)
        );
    }
}
