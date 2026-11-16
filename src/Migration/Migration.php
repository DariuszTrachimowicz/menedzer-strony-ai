<?php

declare(strict_types=1);

namespace ASM\Migration;

final class Migration
{
    public static function run(): void
    {
        if (get_option('asm_migrated_to', '') === ASM_VERSION) {
            return;
        }

        // Opcje v1, w tym asm_key_hash i asm_popup, pozostają nietknięte.
        if (get_option('asm_v1_compat_until', null) === null) {
            update_option('asm_v1_compat_until', 0, false);
        }
        update_option('asm_migrated_to', ASM_VERSION, false);
    }

    public static function revokeLegacyKey(): void
    {
        delete_option('asm_key_hash');
        update_option('asm_v1_compat_until', 0, false);
    }
}
