<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

/**
 * Popup i pasek informacyjny są od 2.9.0 we wtyczce Digital Xperts Popup. Zasób „main” to popup, „bar” to pasek.
 * Bez tej wtyczki odczyt zwraca błąd, a operacja popup.update odmawia przed zmianą.
 */
final class PopupResource
{
    private const PARTS = array(
        'main' => 'DigitalXperts\Popup\Popup',
        'bar' => 'DigitalXperts\Popup\Bar',
    );

    public function list(array $params = array()): array
    {
        $items = array();
        foreach (array_keys(self::PARTS) as $id) {
            $item = $this->get($id);
            if (! $item instanceof \WP_Error) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function get(string $id)
    {
        $class = $this->part($id);
        return $class instanceof \WP_Error ? $class : array('id' => $id) + $class::settings();
    }

    public function snapshot(array $target): array
    {
        return $this->required($target)::settings();
    }

    public function apply(array $target, array $change): void
    {
        $class = $this->required($target);
        $class::save(array_merge($class::settings(), $change));
    }

    public function verify(array $target, array $change): ?bool
    {
        $settings = $this->required($target)::settings();
        foreach ($change as $key => $value) {
            if (! array_key_exists($key, $settings) || $settings[$key] != $value) {
                return false;
            }
        }
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        $this->required($target)::save($snapshot);
    }

    /** @return string|\WP_Error */
    private function part(string $id)
    {
        $class = self::PARTS[$id] ?? null;
        if ($class === null) {
            return new \WP_Error('asm_resource_not_found', 'Nieznany zasób. Dostępne: main (popup) i bar (pasek informacyjny).', array('status' => 404));
        }
        if (! class_exists($class)) {
            return new \WP_Error('asm_popup_plugin_missing', 'Popup i pasek są we wtyczce Digital Xperts Popup. Zainstaluj ją operacją plugin.install ze slugiem digital-xperts-popup i włącz.', array('status' => 409));
        }
        return $class;
    }

    private function required(array $target): string
    {
        $class = $this->part((string) ($target['id'] ?? 'main'));
        if ($class instanceof \WP_Error) {
            throw new OperationFailure((string) $class->get_error_code(), $class->get_error_message());
        }
        return $class;
    }
}
