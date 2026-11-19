<?php

declare(strict_types=1);

namespace ASM\Resources;

final class SecretDetector
{
    public static function isSecretName(string $name): bool
    {
        return preg_match(
            '/(?:pass(?:word)?|secret|token|api[_-]?key|auth[_-]?key|salt|cookie|session)/i',
            $name
        ) === 1;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function publicValue(string $name, $value, int $depth = 0)
    {
        if (self::isSecretName($name)) {
            return '[REDACTED]';
        }
        // Hasła siedzą też w opcjach złożonych, np. wp_mail_smtp['smtp']['pass'].
        if ((is_array($value) || is_object($value)) && $depth < 32) {
            $public = array();
            foreach ((array) $value as $key => $item) {
                $public[$key] = self::publicValue((string) $key, $item, $depth + 1);
            }
            return $public;
        }
        return $value;
    }
}
