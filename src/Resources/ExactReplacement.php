<?php

declare(strict_types=1);

namespace ASM\Resources;

final class ExactReplacement
{
    /** @return string|\WP_Error */
    public static function apply(string $content, string $old, string $new, int $expectedOccurrences)
    {
        if ($old === '' || $expectedOccurrences < 1) {
            return new \WP_Error(
                'asm_invalid_search_value',
                'Szukana wartość nie może być pusta, a liczba wystąpień musi być dodatnia.',
                array('status' => 400)
            );
        }

        $actual = substr_count($content, $old);
        if ($actual !== $expectedOccurrences) {
            return new \WP_Error(
                'asm_ambiguous_target',
                'Liczba wystąpień nie zgadza się z oczekiwanym stanem.',
                array(
                    'status' => 409,
                    'expected_occurrences' => $expectedOccurrences,
                    'actual_occurrences' => $actual,
                    'content_hash' => hash('sha256', $content),
                )
            );
        }

        $replaced = str_replace($old, $new, $content, $replacements);
        if ($replacements !== $expectedOccurrences) {
            return new \WP_Error(
                'asm_replacement_failed',
                'Nie udało się wykonać dokładnej podmiany.',
                array('status' => 500)
            );
        }

        return $replaced;
    }
}
