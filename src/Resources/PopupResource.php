<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Popup\Popup;

final class PopupResource
{
    public function list(array $params = array()): array
    {
        return array($this->get('main'));
    }

    public function get(string $id): array
    {
        return array('id' => 'main') + Popup::settings();
    }

    public function snapshot(array $target): array
    {
        return Popup::settings();
    }

    public function apply(array $target, array $change): void
    {
        Popup::save(array_merge(Popup::settings(), $change));
    }

    public function verify(array $target, array $change): ?bool
    {
        $settings = Popup::settings();
        foreach ($change as $key => $value) {
            if (! array_key_exists($key, $settings) || $settings[$key] != $value) {
                return false;
            }
        }
        return true;
    }

    public function rollback(array $target, array $snapshot): void
    {
        Popup::save($snapshot);
    }
}
