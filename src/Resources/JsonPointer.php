<?php

declare(strict_types=1);

namespace ASM\Resources;

final class JsonPointer
{
    /**
     * @param mixed $document
     * @param mixed $old
     * @param mixed $new
     * @return mixed|\WP_Error
     */
    public static function replace($document, string $pointer, $old, $new)
    {
        if ($pointer === '' || $pointer[0] !== '/') {
            return new \WP_Error(
                'asm_invalid_json_pointer',
                'Ścieżka JSON Pointer musi zaczynać się ukośnikiem.',
                array('status' => 400)
            );
        }

        $segments = explode('/', substr($pointer, 1));
        $copy = $document;
        $cursor =& $copy;
        foreach ($segments as $segment) {
            $segment = str_replace(array('~1', '~0'), array('/', '~'), $segment);
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return new \WP_Error(
                    'asm_target_not_found',
                    'Wskazana ścieżka nie istnieje w dokumencie.',
                    array('status' => 404, 'path' => $pointer)
                );
            }
            $cursor =& $cursor[$segment];
        }

        if ($cursor !== $old) {
            return new \WP_Error(
                'asm_precondition_failed',
                'Wartość pod wskazaną ścieżką uległa zmianie.',
                array('status' => 409, 'path' => $pointer)
            );
        }

        $cursor = $new;
        unset($cursor);
        return $copy;
    }
}
