<?php

declare(strict_types=1);

namespace ASM\Auth;

final class ApplicationPasswordGate
{
    /** @return true|\WP_Error */
    public function authorize(string $action)
    {
        if (! is_user_logged_in()) {
            return new \WP_Error(
                'asm_unauthorized',
                'Wymagane jest uwierzytelnienie WordPressa.',
                array('status' => 401)
            );
        }

        $capabilities = CapabilityMap::forAction($action);
        if ($capabilities === array()) {
            return new \WP_Error(
                'asm_unknown_action',
                'Nieznana akcja Menedżera Strony AI.',
                array('status' => 400)
            );
        }

        foreach ($capabilities as $capability) {
            if (! current_user_can($capability)) {
                return new \WP_Error(
                    'asm_forbidden',
                    'Bieżący operator nie ma wymaganego uprawnienia.',
                    array(
                        'status' => 403,
                        'missing_capability' => $capability,
                    )
                );
            }
        }

        return true;
    }
}
