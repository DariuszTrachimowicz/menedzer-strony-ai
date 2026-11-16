<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class TermCreateResource
{
    public function snapshotWithChange(array $target, array $change): array
    {
        $taxonomy = $this->taxonomy($target);
        if (array_diff(array_keys($target), ['type', 'id', '_operation_id'])) {
            throw new OperationFailure('asm_term_target_invalid', 'Nieprawidłowe pola celu.');
        }
        $term = $this->fields($change);

        return [
            'exists' => (bool) get_term_by('slug', $term['slug'], $taxonomy)
                || (bool) get_term_by('name', $term['name'], $taxonomy),
        ];
    }

    public function applyWithSnapshot(array $target, array $change, array $snapshot): array
    {
        $taxonomy = $this->taxonomy($target);
        $term = $this->fields($change);
        if (! empty($snapshot['exists'])) {
            throw new OperationFailure('asm_term_exists', 'Kategoria lub tag już istnieje.');
        }

        $createdIds = [];
        $recordCreation = static function (int $id, int $taxonomyId, string $type) use ($taxonomy, &$createdIds): void {
            if ($type === $taxonomy) {
                $createdIds[] = $id;
            }
        };
        add_action('created_term', $recordCreation, 10, 3);
        try {
            $created = wp_insert_term(wp_slash($term['name']), $taxonomy, wp_slash([
                'slug' => $term['slug'],
                'description' => $term['description'],
            ]));
        } finally {
            remove_action('created_term', $recordCreation, 10);
        }
        if (is_wp_error($created)) {
            throw new OperationFailure($created->get_error_code(), $created->get_error_message());
        }
        if (! in_array((int) $created['term_id'], $createdIds, true)) {
            throw new OperationFailure('asm_term_exists', 'Kategoria lub tag już istnieje.');
        }

        return ['target' => ['term_id' => (int) $created['term_id'], 'created_term' => $term]];
    }

    public function verify(array $target, array $change): bool
    {
        $term = $this->readTerm($target);
        return $term !== null && $this->matches($term, $this->fields($change));
    }

    public function rollback(array $target, array $snapshot): void
    {
        $term = $this->readTerm($target);
        if ($term === null) {
            return;
        }
        $taxonomy = $this->taxonomy($target);
        $children = $taxonomy === 'category'
            ? get_terms(['taxonomy' => $taxonomy, 'parent' => (int) $term->term_id, 'hide_empty' => false, 'fields' => 'ids', 'number' => 1])
            : [];
        if (! empty($snapshot['exists']) || ! isset($target['created_term'])
            || ! $this->matches($term, $target['created_term'])
            || $children !== [] || (int) get_option('default_category') === (int) $term->term_id
            || get_objects_in_term((int) $term->term_id, $taxonomy) !== []
            || get_term_meta((int) $term->term_id) !== []
            || (array) wp_get_associated_nav_menu_items((int) $term->term_id, 'taxonomy', $taxonomy) !== []) {
            throw new OperationFailure('asm_term_in_use', 'Termin został zmieniony lub jest używany.');
        }
        if (wp_delete_term((int) $term->term_id, $taxonomy) !== true) {
            throw new OperationFailure('asm_term_rollback_failed', 'Nie udało się cofnąć utworzenia terminu.');
        }
    }

    public function verifyRollback(array $target, array $snapshot): bool
    {
        return $this->readTerm($target) === null;
    }

    private function taxonomy(array $target): string
    {
        $taxonomy = $target['id'] ?? '';
        if (($target['type'] ?? '') !== 'terms' || ! in_array($taxonomy, ['category', 'post_tag'], true)
            || ! current_user_can('manage_categories')) {
            throw new OperationFailure('asm_term_forbidden', 'Dozwolone są kategorie i tagi wpisów. Wymagane uprawnienie do zarządzania nimi.');
        }
        return $taxonomy;
    }

    private function fields(array $change): array
    {
        $name = $change['name'] ?? null;
        $slug = $change['slug'] ?? null;
        $description = $change['description'] ?? '';
        if (array_diff(array_keys($change), ['name', 'slug', 'description'])
            || ! is_string($name) || $name !== trim($name) || $name === '' || strlen($name) > 200
            || sanitize_text_field($name) !== $name
            || ! is_string($slug) || strlen($slug) > 190 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
            || ! is_string($description) || strlen($description) > 2000
            || sanitize_textarea_field($description) !== $description) {
            throw new OperationFailure('asm_term_invalid', 'Podaj nazwę i prosty slug. Opis jest opcjonalny i obsługuje zwykły tekst.');
        }
        return ['name' => $name, 'slug' => $slug, 'description' => $description];
    }

    private function readTerm(array $target): ?\WP_Term
    {
        $taxonomy = $this->taxonomy($target);
        if (empty($target['term_id'])) {
            return null;
        }
        $term = get_term((int) $target['term_id'], $taxonomy);
        if (is_wp_error($term)) {
            throw new OperationFailure($term->get_error_code(), $term->get_error_message());
        }
        return $term instanceof \WP_Term ? $term : null;
    }

    private function matches(\WP_Term $term, array $fields): bool
    {
        return $term->name === $fields['name'] && $term->slug === $fields['slug']
            && $term->description === $fields['description'] && (int) $term->parent === 0;
    }
}
