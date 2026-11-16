<?php

declare(strict_types=1);

namespace ASM\Media;

final class UploadValidator
{
    private int $maxBytes;

    public function __construct(int $maxBytes = 20971520)
    {
        $this->maxBytes = $maxBytes;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function validate(string $path, string $claimedName)
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new \WP_Error('asm_upload_unreadable', 'Nie można odczytać przesłanego pliku.', array('status' => 400));
        }
        $size = filesize($path);
        if (! is_int($size) || $size < 1) {
            return new \WP_Error('asm_upload_empty', 'Przesłany plik jest pusty.', array('status' => 400));
        }
        if ($size > $this->maxBytes) {
            return new \WP_Error(
                'asm_upload_too_large',
                'Przesłany plik przekracza limit rozmiaru.',
                array('status' => 413, 'size' => $size, 'max_bytes' => $this->maxBytes)
            );
        }

        $extension = strtolower((string) pathinfo($claimedName, PATHINFO_EXTENSION));
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
        $allowed = array(
            'image/jpeg' => array('jpg', 'jpeg'),
            'image/png' => array('png'),
            'image/webp' => array('webp'),
            'image/gif' => array('gif'),
            'application/pdf' => array('pdf'),
        );
        if (! isset($allowed[$mime])) {
            return new \WP_Error('asm_upload_type_forbidden', 'Ten typ pliku nie jest dozwolony.', array('status' => 415, 'mime' => $mime));
        }
        if (! in_array($extension, $allowed[$mime], true)) {
            return new \WP_Error(
                'asm_upload_mime_mismatch',
                'Rozszerzenie pliku nie zgadza się z jego rzeczywistym typem.',
                array('status' => 415, 'mime' => $mime, 'extension' => $extension)
            );
        }

        $result = array(
            'path' => $path,
            'name' => basename($claimedName),
            'extension' => $extension,
            'mime' => $mime,
            'size' => $size,
            'sha256' => hash_file('sha256', $path),
        );
        if (strpos($mime, 'image/') === 0) {
            $dimensions = @getimagesize($path);
            if (! is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
                return new \WP_Error('asm_upload_broken_image', 'Obrazu nie można bezpiecznie zdekodować.', array('status' => 415));
            }
            $result['width'] = (int) $dimensions[0];
            $result['height'] = (int) $dimensions[1];
        }

        return $result;
    }
}
