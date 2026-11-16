<?php

declare(strict_types=1);

namespace ASM\Rest;

final class Schema
{
    public static function operationPreviewArgs(): array
    {
        return array(
            'action' => self::stringArgument(true, 100),
            'target' => self::objectArgument(true),
            'expected' => self::objectArgument(true),
            'change' => self::objectArgument(true),
            'verification' => self::objectArgument(false),
        );
    }

    /** @param mixed $value */
    public static function sanitizeBoolean($value): bool
    {
        return rest_sanitize_boolean($value);
    }

    public static function searchArgs(): array
    {
        return array(
            'needle' => self::stringArgument(true, 500),
            'areas' => array(
                'required' => true,
                'type' => 'array',
                'items' => array('type' => 'string'),
                'sanitize_callback' => static function ($value): array {
                    if (! is_array($value)) {
                        return array();
                    }

                    return array_values(array_filter(array_map('sanitize_key', $value)));
                },
                'validate_callback' => static function ($value): bool {
                    return is_array($value) && $value !== array();
                },
            ),
            'filters' => self::objectArgument(false),
        );
    }

    public static function idArgs(): array
    {
        return array(
            'id' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => static function ($value): bool {
                    return is_string($value)
                        && preg_match('/^[a-f0-9-]{36}$/', $value) === 1;
                },
            ),
        );
    }

    public static function resourceArgs(bool $withId): array
    {
        $args = array(
            'type' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => static function ($value): bool {
                    return is_string($value)
                        && preg_match('/^[a-z_]+$/', $value) === 1;
                },
            ),
        );

        if ($withId) {
            $args['id'] = self::stringArgument(true, 190);
        }

        return $args;
    }

    private static function stringArgument(bool $required, int $maxLength): array
    {
        return array(
            'required' => $required,
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => static function ($value) use ($maxLength): bool {
                return is_string($value)
                    && $value !== ''
                    && strlen($value) <= $maxLength;
            },
        );
    }

    private static function objectArgument(bool $required): array
    {
        return array(
            'required' => $required,
            'type' => 'object',
            'sanitize_callback' => static function ($value) {
                return is_array($value) ? $value : array();
            },
            'validate_callback' => static function ($value) use ($required): bool {
                return is_array($value) && (! $required || $value !== array());
            },
        );
    }
}
