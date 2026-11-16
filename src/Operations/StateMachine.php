<?php

declare(strict_types=1);

namespace ASM\Operations;

final class StateMachine
{
    /** @var array<string,array<int,string>> */
    private array $transitions = array(
        'previewed' => array('executing'),
        'executing' => array('verified', 'failed', 'unknown'),
        'failed' => array('rolling_back'),
        'verified' => array('rolling_back'),
        'unknown' => array('verified', 'failed', 'rolling_back'),
        'rolling_back' => array('rolled_back', 'rollback_failed'),
    );

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->transitions[$from] ?? array(), true);
    }

    /** @return Operation|\WP_Error */
    public function transition(Operation $operation, string $status, ?string $errorCode = null)
    {
        if (! $this->canTransition($operation->status(), $status)) {
            return new \WP_Error(
                'asm_invalid_transition',
                'Niedozwolona zmiana stanu operacji.',
                array(
                    'status' => 409,
                    'from' => $operation->status(),
                    'to' => $status,
                )
            );
        }

        $operation->setStatus($status, $errorCode);
        return $operation;
    }
}
