<?php

declare(strict_types=1);

require __DIR__ . '/fixture.php';

$h = taxonomyRegistry(new stdClass())->handler('term.create');
foreach (['category', 'post_tag'] as $taxonomy) {
    $target = ['type' => 'terms', 'id' => $taxonomy];
    $change = ['name' => 'Reklamy w ChatGPT', 'slug' => 'reklamy-w-chatgpt'];
    $snapshot = $h->snapshotWithChange($target, $change);
    check($snapshot === ['exists' => false], 'preview must not create anything');

    $created = array_merge($target, $h->applyWithSnapshot($target, $change, $snapshot)['target']);
    check($h->verify($created, $change), 'exact requested term must exist');
    $duplicate = $h->snapshotWithChange($target, $change);
    check($duplicate === ['exists' => true], 'duplicate must be visible in preview');
    fails(fn () => $h->applyWithSnapshot($target, $change, $duplicate), 'asm_term_exists');

    $GLOBALS['term_race'] = true;
    fails(fn () => $h->applyWithSnapshot($target, $change, $snapshot), 'asm_term_exists');
    $GLOBALS['term_race'] = false;

    $GLOBALS['assigned'] = [5475];
    fails(fn () => $h->rollback($created, $snapshot), 'asm_term_in_use');
    $GLOBALS['assigned'] = [];
    $h->rollback($created, $snapshot);
    check($h->verifyRollback($created, $snapshot), 'only a newly created unused term can be rolled back');
}
fails(fn () => $h->snapshotWithChange(['type' => 'terms', 'id' => 'nav_menu'], $change), 'asm_term_forbidden');
fails(fn () => $h->snapshotWithChange($target, ['name' => '<b>test</b>', 'slug' => 'test']), 'asm_term_invalid');
$GLOBALS['cap'] = false;
fails(fn () => $h->snapshotWithChange($target, $change), 'asm_term_forbidden');
echo "PASS: native term creation contract for category and post_tag\n";
