<?php

declare(strict_types=1);

require __DIR__ . '/fixture.php';

$postState = ['category' => [33], 'post_tag' => [42, 44]];
$canEdit = true;
$postType = 'post';
function get_post(int $id): ?object
{
    return $id === 5475 ? (object) ['post_type' => $GLOBALS['postType'], 'post_status' => 'draft'] : null;
}
function get_taxonomy(string $taxonomy): object { return (object) ['cap' => (object) ['assign_terms' => 'edit_posts']]; }
function wp_get_object_terms(int $id, string $taxonomy, array $args): array { return $GLOBALS['postState'][$taxonomy]; }
function wp_set_object_terms(int $id, array $ids, string $taxonomy, bool $append): array
{
    $before = $GLOBALS['postState'][$taxonomy];
    return $GLOBALS['postState'][$taxonomy] = $append ? array_values(array_unique([...$before, ...$ids])) : $ids;
}
function wp_remove_object_terms(int $id, array $ids, string $taxonomy): bool
{
    $GLOBALS['postState'][$taxonomy] = array_values(array_diff($GLOBALS['postState'][$taxonomy], $ids));
    return true;
}

$terms = [
    'category' => [33 => new WP_Term(33, 'AI', 'ai', ''), 55 => new WP_Term(55, 'Reklamy w ChatGPT', 'reklamy-w-chatgpt', '')],
    'post_tag' => [42 => new WP_Term(42, 'Analiza', 'analiza', ''), 44 => new WP_Term(44, 'ChatGPT', 'chatgpt', ''), 56 => new WP_Term(56, 'ChatGPT Ads', 'chatgpt-ads', '')],
];

$registry = taxonomyRegistry(new stdClass());
$target = ['type' => 'posts', 'id' => 5475];
$move = $registry->handler('post.category.move');
$append = $registry->handler('post.tags.append');

$change = ['category_id' => 55];
$snapshot = $move->snapshotWithChange($target, $change);
check($snapshot['before'] === [33] && $snapshot['after'] === [55] && $postState['category'] === [33], 'Preview must be read-only.');
$postState['category'] = [33, 55];
fails(fn () => $move->applyWithSnapshot($target, $change, $snapshot), 'asm_post_term_conflict');
$postState['category'] = [33];
$done = array_merge($target, $move->applyWithSnapshot($target, $change, $snapshot)['target']);
check($move->verify($done, $change) && $postState === ['category' => [55], 'post_tag' => [42, 44]], 'Move must replace category and preserve tags.');
$move->rollback($done, $snapshot);
check($move->verifyRollback($done, $snapshot) && $postState['category'] === [33], 'Rollback must restore original category.');

$change = ['tag_ids' => [56, 44, 56]];
$snapshot = $append->snapshotWithChange($target, $change);
$done = array_merge($target, $append->applyWithSnapshot($target, $change, $snapshot)['target']);
check($append->verify($done, $change) && $postState['post_tag'] === [42, 44, 56], 'Append must preserve old tags and deduplicate IDs.');
$noop = $append->snapshotWithChange($target, $change);
$noChange = array_merge($target, $append->applyWithSnapshot($target, $change, $noop)['target']);
check($noChange['assignment_changed'] === false && $append->verify($noChange, $change), 'Existing tags are a successful no-op.');
$postState['post_tag'][] = 59;
fails(fn () => $append->rollback($done, $snapshot), 'asm_post_term_conflict');
array_pop($postState['post_tag']);
$append->rollback($done, $snapshot);
check($append->verifyRollback($done, $snapshot), 'Rollback must restore original tags.');

$remove = $registry->handler('post.tags.remove');
$change = ['tag_ids' => [42, 56, 42]];
$snapshot = $remove->snapshotWithChange($target, $change);
check($snapshot['before'] === [42, 44] && $snapshot['after'] === [44], 'Preview excludes only requested assigned tags.');
$done = array_merge($target, $remove->applyWithSnapshot($target, $change, $snapshot)['target']);
check($remove->verify($done, $change) && $postState['category'] === [33] && isset($terms['post_tag'][42]), 'Remove only the association.');
$noop = $remove->snapshotWithChange($target, $change);
$noChange = array_merge($target, $remove->applyWithSnapshot($target, $change, $noop)['target']);
check($noChange['assignment_changed'] === false && $remove->verify($noChange, $change), 'Unassigned tags are a no-op.');
$postState['post_tag'][] = 56;
fails(fn () => $remove->rollback($done, $snapshot), 'asm_post_term_conflict');
array_pop($postState['post_tag']);
$remove->rollback($done, $snapshot);
check($remove->verifyRollback($done, $snapshot), 'Rollback restores the removed association.');
fails(fn () => $move->snapshotWithChange($target, ['category_id' => 56]), 'asm_post_term_missing');
fails(fn () => $append->snapshotWithChange($target, ['tag_ids' => ['56']]), 'asm_post_term_change_invalid');
fails(fn () => $append->snapshotWithChange($target + ['assignment_after' => []], $change), 'asm_post_term_target_invalid');
$postType = 'page';
fails(fn () => $append->snapshotWithChange($target, $change), 'asm_post_term_post_missing');
$postType = 'post';
$cap = false;
fails(fn () => $append->snapshotWithChange($target, $change), 'asm_post_term_forbidden');

echo "PASS: category move, tag append, conflict, rollback and permissions\n";
