<?php

declare(strict_types=1);

namespace ASM\Storage;

/**
 * Sprzątanie raz dziennie (zadanie asm_mcp_cleanup): każdy podgląd i wykonanie zapisuje kopię,
 * więc bez tego katalog kopii i tabela operacji rosną bez końca.
 * Audyt zostaje: to krótkie wiersze i historia zmian strony.
 */
final class Retention
{
    public const OPERATION_DAYS = 30;
    public const ORPHAN_HOURS = 24;
    private const KEEP_STATUSES = "('executing', 'rolling_back', 'unknown')";

    public static function run(object $database, ?PrivateStorage $storage): void
    {
        $prefix = (string) ($database->prefix ?? '');
        $operations = $prefix . 'asm_operations';
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::OPERATION_DAYS * 86400);
        // Operacje w toku albo w stanie niepewnym zostają, nawet stare: ich kopia może być jedyną drogą powrotu.
        $old = (array) $database->get_col($database->prepare(
            "SELECT id FROM {$operations} WHERE updated_at < %s AND status NOT IN " . self::KEEP_STATUSES,
            $cutoff
        ));
        if (! empty($database->last_error)) {
            return;
        }
        foreach (array_chunk(array_map('strval', $old), 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            // Warunki powtórzone przy usuwaniu: operacja, która w międzyczasie ruszyła, ma świeże updated_at i zostaje.
            $database->query($database->prepare(
                "DELETE FROM {$operations} WHERE id IN ({$in}) AND updated_at < %s AND status NOT IN " . self::KEEP_STATUSES,
                array_merge($chunk, array($cutoff))
            ));
            foreach (array($prefix . 'asm_idempotency', $prefix . 'asm_operation_steps') as $table) {
                $database->query($database->prepare(
                    "DELETE FROM {$table} WHERE operation_id IN ({$in}) AND NOT EXISTS (SELECT 1 FROM {$operations} o WHERE o.id = {$table}.operation_id)",
                    $chunk
                ));
            }
        }
        if ($storage === null) {
            return;
        }
        $live = array_flip(array_map('strval', (array) $database->get_col("SELECT id FROM {$operations}")));
        // Bez pewnej listy operacji nie wolno uznać żadnej kopii za osieroconą.
        if (! empty($database->last_error)) {
            return;
        }
        $removed = array_diff_key(array_flip(array_map('strval', $old)), $live);
        $orphanCutoff = time() - self::ORPHAN_HOURS * 3600;
        foreach ($storage->directories() as $name => $modified) {
            // Kopia stanu po wykonaniu ma nazwę „<id>-after”.
            $operationId = (string) preg_replace('/-after$/', '', $name);
            // Kopie usuniętych właśnie operacji znikają od razu; inne katalogi bez operacji (wgrane pliki,
            // przerwane podglądy) po dobie, żeby nie usunąć kopii podglądu, który właśnie powstaje.
            if (isset($removed[$operationId]) || (! isset($live[$operationId]) && $modified < $orphanCutoff)) {
                $storage->remove($name);
            }
        }
    }
}
