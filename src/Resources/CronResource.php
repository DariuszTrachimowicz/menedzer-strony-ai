<?php

declare(strict_types=1);

namespace ASM\Resources;

use ASM\Operations\OperationFailure;

final class CronResource
{
    private string $mode;

    public function __construct(string $mode = 'schedule')
    {
        $this->mode = $mode;
    }

    public function list(array $params = array()): array
    {
        $events = array();
        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $instances) {
                foreach ((array) $instances as $key => $event) {
                    $events[] = array(
                        'id' => $timestamp . ':' . $hook . ':' . $key,
                        'timestamp' => (int) $timestamp,
                        'hook' => (string) $hook,
                        'schedule' => $event['schedule'] ?? false,
                        'args' => $event['args'] ?? array(),
                        'overdue' => (int) $timestamp < time(),
                    );
                }
            }
        }
        return $events;
    }

    public function get(string $id)
    {
        foreach ($this->list() as $event) {
            if ($event['id'] === $id) {
                return $event;
            }
        }
        return new \WP_Error('asm_resource_not_found', 'Nie znaleziono zdarzenia cron.', array('status' => 404));
    }

    public function snapshot(array $target): array
    {
        return array('cron' => _get_cron_array());
    }

    public function apply(array $target, array $change): void
    {
        $hook = (string) ($target['hook'] ?? $change['hook'] ?? '');
        $args = (array) ($change['args'] ?? $target['args'] ?? array());
        if ($hook === '') {
            throw new OperationFailure('asm_invalid_cron_hook', 'Brak nazwy hooka cron.');
        }
        if ($this->mode === 'run') {
            do_action_ref_array($hook, $args);
            return;
        }
        if ($this->mode === 'delete') {
            $timestamp = (int) ($target['timestamp'] ?? 0);
            wp_unschedule_event($timestamp, $hook, $args);
            return;
        }
        $timestamp = (int) ($change['timestamp'] ?? time());
        $schedule = (string) ($change['schedule'] ?? '');
        $result = $schedule === ''
            ? wp_schedule_single_event($timestamp, $hook, $args, true)
            : wp_schedule_event($timestamp, $schedule, $hook, $args, true);
        if (is_wp_error($result) || $result === false) {
            throw new OperationFailure('asm_cron_schedule_failed', 'Nie udało się zaplanować zdarzenia cron.');
        }
    }

    public function verify(array $target, array $change): ?bool
    {
        if ($this->mode === 'run') {
            return true;
        }
        $hook = (string) ($target['hook'] ?? $change['hook'] ?? '');
        $args = (array) ($change['args'] ?? $target['args'] ?? array());
        $scheduled = wp_next_scheduled($hook, $args);
        return $this->mode === 'delete' ? $scheduled === false : $scheduled !== false;
    }

    public function rollback(array $target, array $snapshot): void
    {
        _set_cron_array((array) $snapshot['cron'], true);
    }
}
