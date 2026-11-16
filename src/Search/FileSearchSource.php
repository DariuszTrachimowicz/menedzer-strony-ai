<?php

declare(strict_types=1);

namespace ASM\Search;

use ASM\Filesystem\PathPolicy;
use ASM\Resources\SecretDetector;

final class FileSearchSource
{
    private PathPolicy $paths;

    public function __construct(PathPolicy $paths)
    {
        $this->paths = $paths;
    }

    public function records(array $filters): array
    {
        $requested = (string) ($filters['path'] ?? '.');
        $root = $this->paths->resolve($requested, true);
        if ($root instanceof \WP_Error || ! is_dir($root)) {
            return array();
        }
        $extensions = array('php', 'css', 'js', 'json', 'html', 'htm', 'xml', 'ini', 'txt', 'md');
        $limit = max(1, min(2000, (int) ($filters['file_limit'] ?? 500)));
        $records = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (count($records) >= $limit || $file->isLink() || ! $file->isFile() || $file->getSize() > 2097152) {
                continue;
            }
            if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (! is_string($content) || strpos($content, "\0") !== false) {
                continue;
            }
            $records[] = array(
                'resource_type' => 'file',
                'resource_id' => str_replace('\\', '/', $file->getPathname()),
                'path' => str_replace('\\', '/', $file->getPathname()),
                'content' => $content,
                'secret' => SecretDetector::isSecretName($file->getFilename()) || strtolower($file->getFilename()) === 'wp-config.php',
            );
        }
        return $records;
    }
}
