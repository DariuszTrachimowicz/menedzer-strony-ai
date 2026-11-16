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
    public static function publicValue(string $name, $value)
    {
        return self::isSecretName($name) ? '[REDACTED]' : $value;
    }
}
