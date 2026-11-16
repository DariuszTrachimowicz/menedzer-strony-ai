<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class PostTermResource
{
    public function __construct(private string $taxonomy, private bool $remove = false)
    {
        if (! in_array($taxonomy, ['category', 'post_tag'], true) || ($remove && $taxonomy !== 'post_tag')) {
            throw new \InvalidArgumentException('Unsupported post taxonomy.');
        }
    }

    public function snapshotWithChange(array $target, array $change): array
    {
        if (array_diff(array_keys($target), ['type', 'id', '_operation_id'])) {
            throw new OperationFailure('asm_post_term_target_invalid', 'Podaj typ posts i identyfikator wpisu.');
        }

        $postId = $this->postId($target);
        $requested = $this->requestedIds($change);
        $before = $this->assignedIds($postId);
        if ($this->remove) {
            $after = array_values(array_diff($before, $requested));
        } else {
            $after = $this->taxonomy === 'post_tag' ? $this->sortedIds(array_merge($before, $requested)) : $requested;
        }

        return ['exists' => true, 'taxonomy' => $this->taxonomy, 'before' => $before, 'after' => $after];
    }

    public function applyWithSnapshot(array $target, array $change, array $snapshot): array
    {
        $postId = $this->postId($target);
        $requested = $this->requestedIds($change);
        if ($this->assignedIds($postId) !== $snapshot['before']) {
            throw new OperationFailure('asm_post_term_conflict', 'Przypisania zmieniły się po podglądzie.');
        }

        $changed = $snapshot['before'] !== $snapshot['after'];
        if ($changed && $this->remove) {
            $removed = wp_remove_object_terms($postId, array_values(array_diff($snapshot['before'], $snapshot['after'])), $this->taxonomy);
            if ($removed !== true) {
                $message = is_wp_error($removed) ? $removed->get_error_message() : 'Nie udało się odłączyć tagów.';
                throw new OperationFailure('asm_post_term_write_failed', $message);
            }
        } elseif ($changed) {
            $this->write($postId, $requested, $this->taxonomy === 'post_tag');
        }

        return ['target' => ['assignment_changed' => $changed, 'assignment_after' => $snapshot['after']]];
    }

    public function verify(array $target, array $change): bool
    {
        return isset($target['assignment_after'])
            && $this->assignedIds($this->postId($target)) === $target['assignment_after'];
    }

    public function rollback(array $target, array $snapshot): void
    {
        if (empty($target['assignment_changed'])) {
            return;
        }

        $postId = $this->postId($target);
        $current = $this->assignedIds($postId);
        if ($current === $snapshot['before']) {
            return;
        }
        if ($current !== $snapshot['after']) {
            throw new OperationFailure('asm_post_term_conflict', 'Późniejsze przypisania blokują cofnięcie.');
        }

        $restore = $this->remove ? array_values(array_diff($snapshot['before'], $snapshot['after'])) : $snapshot['before'];
        $this->assertExisting($restore);
        $this->write($postId, $restore, $this->remove);
    }

    public function verifyRollback(array $target, array $snapshot): bool
    {
        return ($target['assignment_changed'] ?? null) === false
            || $this->assignedIds($this->postId($target)) === $snapshot['before'];
    }

    private function postId(array $target): int
    {
        $id = $target['id'] ?? null;
        if (($target['type'] ?? null) !== 'posts' || ! is_int($id) || $id < 1) {
            throw new OperationFailure('asm_post_term_target_invalid', 'Podaj dodatni identyfikator istniejącego wpisu jako liczbę.');
        }
        $post = get_post($id);
        if (! $post || $post->post_type !== 'post' || in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            throw new OperationFailure('asm_post_term_post_missing', 'Nie znaleziono wpisu do edycji.');
        }
        $taxonomy = get_taxonomy($this->taxonomy);
        if (! $taxonomy || ! current_user_can('edit_post', $id) || ! current_user_can($taxonomy->cap->assign_terms)) {
            throw new OperationFailure('asm_post_term_forbidden', 'Wymagane są uprawnienia do edycji wpisu i przypisywania terminów.');
        }

        return $id;
    }

    private function requestedIds(array $change): array
    {
        if ($this->taxonomy === 'category') {
            $ids = [$change['category_id'] ?? null];
            $valid = array_keys($change) === ['category_id'];
        } else {
            $ids = $change['tag_ids'] ?? null;
            $valid = array_keys($change) === ['tag_ids'] && is_array($ids)
                && array_is_list($ids) && count($ids) >= 1 && count($ids) <= 100;
        }
        if (! $valid) {
            throw new OperationFailure('asm_post_term_change_invalid', 'Podaj category_id albo od 1 do 100 identyfikatorów w tag_ids.');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new OperationFailure('asm_post_term_change_invalid', 'Identyfikatory terminów muszą być dodatnimi liczbami całkowitymi.');
            }
        }

        $ids = $this->sortedIds($ids);
        $this->assertExisting($ids);
        return $ids;
    }

    private function assertExisting(array $ids): void
    {
        foreach ($ids as $id) {
            if (! get_term($id, $this->taxonomy) instanceof \WP_Term) {
                throw new OperationFailure('asm_post_term_missing', 'Termin nie istnieje w wymaganej taksonomii: ' . $id);
            }
        }
    }

    private function assignedIds(int $postId): array
    {
        $ids = wp_get_object_terms($postId, $this->taxonomy, ['fields' => 'ids']);
        if (is_wp_error($ids)) {
            throw new OperationFailure('asm_post_term_read_failed', $ids->get_error_message());
        }

        return $this->sortedIds(array_map('intval', $ids));
    }

    private function write(int $postId, array $ids, bool $append): void
    {
        $result = wp_set_object_terms($postId, $ids, $this->taxonomy, $append);
        if (is_wp_error($result)) {
            throw new OperationFailure('asm_post_term_write_failed', $result->get_error_message());
        }
    }

    private function sortedIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
