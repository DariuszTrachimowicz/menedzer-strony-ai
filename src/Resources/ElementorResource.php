<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class ElementorResource
{
    /** @return array<string,mixed> */
    public function snapshot(array $target): array
    {
        $postId = (int) ($target['id'] ?? 0);
        $raw = get_post_meta($postId, '_elementor_data', true);
        if (! is_string($raw) || $raw === '') {
            throw new OperationFailure('asm_elementor_data_not_found', 'Brak danych Elementora dla wpisu.');
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new OperationFailure('asm_invalid_elementor_json', 'Dane Elementora nie są poprawnym JSON-em.');
        }
        return array(
            'raw' => $raw,
            'data' => $data,
            'hash' => hash('sha256', $raw),
        );
    }

    public function apply(array $target, array $change): void
    {
        $postId = (int) ($target['id'] ?? 0);
        $snapshot = $this->snapshot($target);
        $updated = JsonPointer::replace(
            $snapshot['data'],
            (string) ($change['path'] ?? ''),
            $change['old'] ?? null,
            $change['new'] ?? null
        );
        if ($updated instanceof \WP_Error) {
            throw new OperationFailure($updated->get_error_code(), $updated->get_error_message());
        }
        $json = wp_json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new OperationFailure('asm_invalid_elementor_json', 'Nie udało się zakodować danych Elementora.');
        }
        update_post_meta($postId, '_elementor_data', wp_slash($json));
        $this->clearElementorCache($postId);
    }

    public function verify(array $target, array $change): ?bool
    {
        try {
            $snapshot = $this->snapshot($target);
        } catch (OperationFailure $error) {
            return false;
        }
        $value = $this->valueAt($snapshot['data'], (string) ($change['path'] ?? ''));
        return $value['found'] && $value['value'] === ($change['new'] ?? null);
    }

    public function rollback(array $target, array $snapshot): void
    {
        $postId = (int) ($target['id'] ?? 0);
        update_post_meta($postId, '_elementor_data', wp_slash((string) $snapshot['raw']));
        $this->clearElementorCache($postId);
    }

    /** @return array{found:bool,value:mixed} */
    private function valueAt(array $document, string $pointer): array
    {
        if ($pointer === '' || $pointer[0] !== '/') {
            return array('found' => false, 'value' => null);
        }
        $value = $document;
        foreach (explode('/', substr($pointer, 1)) as $segment) {
            $segment = str_replace(array('~1', '~0'), array('/', '~'), $segment);
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return array('found' => false, 'value' => null);
            }
            $value = $value[$segment];
        }
        return array('found' => true, 'value' => $value);
    }

    private function clearElementorCache(int $postId): void
    {
        if (class_exists('\\Elementor\\Plugin')) {
            $plugin = \Elementor\Plugin::$instance;
            if (isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
                $plugin->files_manager->clear_cache();
            }
        }
        clean_post_cache($postId);
    }
}
