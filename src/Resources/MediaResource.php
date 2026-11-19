<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Media\StagingService;
use ASM\Operations\OperationFailure;
use ASM\Storage\PrivateStorage;

final class MediaResource
{
    private string $mode;
    private StagingService $staging;
    private PrivateStorage $storage;

    public function __construct(string $mode, StagingService $staging, PrivateStorage $storage)
    {
        $this->mode = $mode;
        $this->staging = $staging;
        $this->storage = $storage;
    }

    public function list(array $params = array()): array
    {
        $query = new \WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => $params['mime_type'] ?? '',
            'posts_per_page' => max(1, min(100, (int) ($params['per_page'] ?? 20))),
            'paged' => max(1, (int) ($params['page'] ?? 1)),
            'orderby' => 'ID',
            'order' => 'DESC',
        ));
        return array_map(array($this, 'publicAttachment'), $query->posts);
    }

    public function get(string $id)
    {
        $post = get_post((int) $id);
        if (! $post || $post->post_type !== 'attachment') {
            return new \WP_Error('asm_resource_not_found', 'Nie znaleziono załącznika.', array('status' => 404));
        }
        $data = $this->publicAttachment($post);
        $data['metadata'] = wp_get_attachment_metadata((int) $post->ID);
        return $data;
    }

    public function snapshot(array $target): array
    {
        $attachmentId = (int) ($target['id'] ?? 0);
        $post = get_post($attachmentId);
        if ($this->mode === 'create' && (! $post || $post->post_type !== 'attachment')) {
            return array('exists' => false);
        }
        if (! $post || $post->post_type !== 'attachment') {
            throw new OperationFailure('asm_resource_not_found', 'Nie znaleziono załącznika.');
        }
        $operationId = (string) ($target['_operation_id'] ?? 'snapshot-' . bin2hex(random_bytes(8)));
        $metadata = wp_get_attachment_metadata($attachmentId);
        $files = $this->attachmentFiles($attachmentId, is_array($metadata) ? $metadata : array());
        $backups = array();
        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }
            $relative = 'media/' . hash('sha256', $path) . '-' . basename($path);
            $backupPath = $this->storage->store($operationId, $relative, (string) file_get_contents($path));
            $backups[] = array(
                'original_path' => $path,
                'backup_path' => $backupPath,
                'sha256' => hash_file('sha256', $path),
            );
        }

        return array(
            'exists' => true,
            'post' => get_object_vars($post),
            'meta' => get_post_meta($attachmentId),
            'metadata' => $metadata,
            'attached_file' => get_attached_file($attachmentId, true),
            'url' => wp_get_attachment_url($attachmentId),
            'backups' => $backups,
        );
    }

    public function apply(array $target, array $change)
    {
        return $this->applyWithSnapshot($target, $change, array());
    }

    public function applyWithSnapshot(array $target, array $change, array $snapshot)
    {
        $attachmentId = (int) ($target['id'] ?? 0);
        if ($this->mode === 'delete') {
            if (! wp_delete_attachment($attachmentId, true)) {
                throw new OperationFailure('asm_media_delete_failed', 'Nie udało się usunąć załącznika.');
            }
            return;
        }
        if ($this->mode === 'update') {
            $fields = array_intersect_key($change, array_flip(array('post_title', 'post_excerpt', 'post_content')));
            if ($fields !== array()) {
                $fields['ID'] = $attachmentId;
                $result = wp_update_post(wp_slash($fields), true);
                if (is_wp_error($result)) {
                    throw new OperationFailure($result->get_error_code(), $result->get_error_message());
                }
            }
            if (array_key_exists('alt', $change)) {
                update_post_meta($attachmentId, '_wp_attachment_image_alt', wp_slash((string) $change['alt']));
            }
            return;
        }

        $resolved = $this->staging->resolve((string) ($change['staging_token'] ?? ''));
        if ($resolved instanceof \WP_Error) {
            throw new OperationFailure($resolved->get_error_code(), $resolved->get_error_message());
        }
        if ($this->mode === 'create') {
            $createdId = $this->createAttachment($resolved, $change);
            return array('target' => array('id' => $createdId));
        }
        if ($this->mode !== 'replace') {
            throw new OperationFailure('asm_unknown_media_action', 'Nieznana operacja na mediach.');
        }

        try {
            $this->replaceFile($attachmentId, $resolved, $snapshot);
            $this->applyTextFields($attachmentId, $change);
        } catch (\Throwable $error) {
            if ($snapshot !== array()) {
                $this->rollback($target, $snapshot);
            }
            if ($error instanceof OperationFailure) {
                throw $error;
            }
            throw new OperationFailure('asm_media_replace_failed', $error->getMessage());
        }
        return null;
    }

    public function verify(array $target, array $change): ?bool
    {
        $attachmentId = (int) ($target['id'] ?? 0);
        if ($this->mode === 'create') {
            $post = get_post($attachmentId);
            return $post !== null && $post->post_type === 'attachment';
        }
        if ($this->mode === 'delete') {
            return get_post($attachmentId) === null;
        }
        if ($this->mode === 'update') {
            $post = get_post($attachmentId);
            return $post !== null;
        }
        $resolved = $this->staging->resolve((string) ($change['staging_token'] ?? ''));
        $file = get_attached_file($attachmentId, true);
        return is_array($resolved) && is_string($file) && is_file($file)
            && hash_equals((string) $resolved['sha256'], (string) hash_file('sha256', $file));
    }

    public function rollback(array $target, array $snapshot): void
    {
        if (empty($snapshot['exists'])) {
            $attachmentId = (int) ($target['id'] ?? 0);
            if ($attachmentId > 0) {
                wp_delete_attachment($attachmentId, true);
            }
            return;
        }
        $attachmentId = (int) ($target['id'] ?? $snapshot['post']['ID'] ?? 0);
        $originalPaths = array();
        foreach ((array) $snapshot['backups'] as $backup) {
            $originalPaths[] = (string) ($backup['original_path'] ?? '');
        }
        $currentMetadata = wp_get_attachment_metadata($attachmentId);
        foreach ($this->attachmentFiles($attachmentId, is_array($currentMetadata) ? $currentMetadata : array()) as $currentPath) {
            if (! in_array($currentPath, $originalPaths, true) && is_file($currentPath)) {
                unlink($currentPath);
            }
        }
        foreach ((array) $snapshot['backups'] as $backup) {
            if (! is_file((string) $backup['backup_path'])) {
                throw new OperationFailure('asm_media_backup_missing', 'Brakuje pliku kopii załącznika.');
            }
            $targetPath = (string) $backup['original_path'];
            if (! is_dir(dirname($targetPath)) && ! mkdir(dirname($targetPath), 0755, true) && ! is_dir(dirname($targetPath))) {
                throw new OperationFailure('asm_media_rollback_failed', 'Nie udało się odtworzyć katalogu załącznika.');
            }
            $temporary = tempnam(dirname($targetPath), '.asm-restore-');
            if ($temporary === false || ! copy((string) $backup['backup_path'], $temporary) || ! rename($temporary, $targetPath)) {
                throw new OperationFailure('asm_media_rollback_failed', 'Nie udało się przywrócić pliku załącznika.');
            }
        }
        $post = (array) $snapshot['post'];
        $post['ID'] = (int) $post['ID'];
        global $wpdb;
        if (get_post($attachmentId) === null) {
            $columns = array_map('strval', (array) $wpdb->get_col("DESCRIBE {$wpdb->posts}", 0));
            $record = array_intersect_key($post, array_flip($columns));
            if ($wpdb->insert($wpdb->posts, $record) === false) {
                throw new OperationFailure('asm_media_rollback_failed', 'Nie udało się odtworzyć rekordu załącznika.');
            }
            clean_post_cache($attachmentId);
        } else {
            $result = wp_update_post(wp_slash($post), true);
            if (is_wp_error($result)) {
                throw new OperationFailure('asm_media_rollback_failed', $result->get_error_message());
            }
        }
        $wpdb->update(
            $wpdb->posts,
            array(
                'post_modified' => (string) $post['post_modified'],
                'post_modified_gmt' => (string) $post['post_modified_gmt'],
            ),
            array('ID' => $attachmentId),
            array('%s', '%s'),
            array('%d')
        );
        foreach (array_keys(get_post_meta($attachmentId)) as $key) {
            delete_post_meta($attachmentId, (string) $key);
        }
        foreach ((array) $snapshot['meta'] as $key => $values) {
            foreach ((array) $values as $value) {
                add_post_meta($attachmentId, (string) $key, wp_slash(maybe_unserialize($value)));
            }
        }
        clean_post_cache($attachmentId);
    }

    public function verifyRollback(array $target, array $snapshot): bool
    {
        $attachmentId = (int) ($target['id'] ?? $snapshot['post']['ID'] ?? 0);
        if (empty($snapshot['exists'])) {
            return get_post($attachmentId) === null;
        }
        $post = get_post($attachmentId);
        if (! $post || $post->post_type !== 'attachment') {
            return false;
        }
        foreach ((array) ($snapshot['backups'] ?? array()) as $backup) {
            $path = (string) ($backup['original_path'] ?? '');
            if (
                ! is_file($path)
                || ! hash_equals((string) ($backup['sha256'] ?? ''), (string) hash_file('sha256', $path))
            ) {
                return false;
            }
        }
        return (string) get_attached_file($attachmentId, true) === (string) ($snapshot['attached_file'] ?? '')
            && (string) $post->post_modified_gmt === (string) ($snapshot['post']['post_modified_gmt'] ?? '');
    }

    private function replaceFile(int $attachmentId, array $resolved, array $snapshot): void
    {
        $destination = get_attached_file($attachmentId, true);
        if (! is_string($destination) || ! is_file($destination)) {
            throw new OperationFailure('asm_media_file_missing', 'Brakuje głównego pliku załącznika.');
        }
        $expectedExtension = strtolower((string) pathinfo($destination, PATHINFO_EXTENSION));
        if ($expectedExtension !== (string) $resolved['extension']) {
            throw new OperationFailure(
                'asm_media_extension_mismatch',
                'Zachowanie adresu wymaga tego samego formatu i rozszerzenia pliku.'
            );
        }
        $temporary = tempnam(dirname($destination), '.asm-replace-');
        if ($temporary === false || ! copy((string) $resolved['path'], $temporary)) {
            throw new OperationFailure('asm_media_copy_failed', 'Nie udało się przygotować nowego pliku.');
        }
        chmod($temporary, fileperms($destination) & 0777);
        if (! rename($temporary, $destination)) {
            @unlink($temporary);
            throw new OperationFailure('asm_media_replace_failed', 'Nie udało się atomowo podmienić pliku.');
        }

        if (! function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $metadata = wp_generate_attachment_metadata($attachmentId, $destination);
        if (! is_array($metadata) || $metadata === array()) {
            throw new OperationFailure('asm_media_metadata_failed', 'Nie udało się wygenerować wariantów obrazu.');
        }
        wp_update_attachment_metadata($attachmentId, $metadata);
        clean_post_cache($attachmentId);
    }

    private function createAttachment(array $resolved, array $change): int
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $temporary = wp_tempnam((string) $resolved['name']);
        if (! is_string($temporary) || ! copy((string) $resolved['path'], $temporary)) {
            throw new OperationFailure('asm_media_copy_failed', 'Nie udało się przygotować uploadu.');
        }
        $result = media_handle_sideload(
            array('name' => (string) $resolved['name'], 'tmp_name' => $temporary),
            (int) ($change['parent_id'] ?? 0),
            (string) ($change['description'] ?? '')
        );
        if (is_wp_error($result)) {
            @unlink($temporary);
            throw new OperationFailure($result->get_error_code(), $result->get_error_message());
        }
        return (int) $result;
    }

    private function applyTextFields(int $attachmentId, array $change): void
    {
        $fields = array_intersect_key($change, array_flip(array('post_title', 'post_excerpt', 'post_content')));
        if ($fields !== array()) {
            $fields['ID'] = $attachmentId;
            $updated = wp_update_post(wp_slash($fields), true);
            if (is_wp_error($updated)) {
                throw new OperationFailure($updated->get_error_code(), $updated->get_error_message());
            }
        }
        if (array_key_exists('alt', $change)) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', wp_slash((string) $change['alt']));
        }
    }

    private function attachmentFiles(int $attachmentId, array $metadata): array
    {
        $main = get_attached_file($attachmentId, true);
        if (! is_string($main) || $main === '') {
            return array();
        }
        $files = array($main);
        $directory = dirname($main);
        foreach ((array) ($metadata['sizes'] ?? array()) as $size) {
            if (! empty($size['file'])) {
                $files[] = $directory . DIRECTORY_SEPARATOR . basename((string) $size['file']);
            }
        }
        return array_values(array_unique($files));
    }

    /** @param object $post */
    public function publicAttachment(object $post): array
    {
        return array(
            'id' => (int) $post->ID,
            'title' => (string) $post->post_title,
            'caption' => (string) $post->post_excerpt,
            'description' => (string) $post->post_content,
            'alt' => (string) get_post_meta((int) $post->ID, '_wp_attachment_image_alt', true),
            'mime' => (string) $post->post_mime_type,
            'url' => (string) wp_get_attachment_url((int) $post->ID),
            'parent' => (int) $post->post_parent,
        );
    }
}
