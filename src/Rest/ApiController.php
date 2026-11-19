<?php

declare(strict_types=1);

namespace ASM\Rest;

use ASM\Inventory\InventoryService;
use ASM\Media\RemoteFetcher;
use ASM\Media\StagingService;
use ASM\Operations\Operation;
use ASM\Operations\OperationEngine;
use ASM\Operations\OperationFailure;
use ASM\Operations\OperationRegistry;
use ASM\Resources\ResourceCatalog;
use ASM\Search\SearchService;
use ASM\Storage\AuditStore;

final class ApiController
{
    private OperationEngine $engine;
    private OperationRegistry $registry;
    private ResourceCatalog $resources;
    private SearchService $search;
    private InventoryService $inventory;
    private AuditStore $audit;
    private ?StagingService $staging;

    public function __construct(
        OperationEngine $engine,
        OperationRegistry $registry,
        ResourceCatalog $resources,
        SearchService $search,
        InventoryService $inventory,
        AuditStore $audit,
        ?StagingService $staging = null
    ) {
        $this->engine = $engine;
        $this->registry = $registry;
        $this->resources = $resources;
        $this->search = $search;
        $this->inventory = $inventory;
        $this->audit = $audit;
        $this->staging = $staging;
    }

    public function inventory($request): array
    {
        return $this->response(array(
            'inventory' => $this->inventory->collect(),
            'resource_types' => $this->resources->types(),
            'actions' => $this->registry->actions(),
        ));
    }

    public function search($request): array
    {
        return $this->response($this->search->search(
            (array) $request->get_param('areas'),
            (string) $request->get_param('needle'),
            (array) $request->get_param('filters')
        ));
    }

    public function preview($request)
    {
        try {
            $operation = $this->engine->preview((array) $request->get_json_params(), get_current_user_id());
            return $this->operationResponse($operation);
        } catch (\Throwable $error) {
            return $this->error($error, 'asm_preview_failed');
        }
    }

    public function execute($request)
    {
        $key = (string) $request->get_header('Idempotency-Key');
        if ($key === '') {
            $key = (string) $request->get_param('idempotency_key');
        }
        if ($key === '' || strlen($key) > 191) {
            return new \WP_Error(
                'asm_idempotency_key_required',
                'Wymagany jest nagłówek Idempotency-Key.',
                array('status' => 400)
            );
        }
        try {
            return $this->operationResponse($this->engine->execute((string) $request->get_param('id'), $key));
        } catch (\Throwable $error) {
            return $this->error($error, 'asm_execution_failed');
        }
    }

    public function operation($request)
    {
        try {
            $operation = $this->engine->get((string) $request->get_param('id'));
            if ($operation->status() === 'unknown') {
                $operation = $this->engine->reconcile($operation->id());
            }
            return $this->operationResponse($operation);
        } catch (\Throwable $error) {
            return $this->error($error, 'asm_operation_not_found', 404);
        }
    }

    public function rollback($request)
    {
        try {
            return $this->operationResponse($this->engine->rollback((string) $request->get_param('id')));
        } catch (\Throwable $error) {
            return $this->error($error, 'asm_rollback_failed');
        }
    }

    public function resources($request)
    {
        $result = $this->resources->list((string) $request->get_param('type'), (array) $request->get_params());
        return $result instanceof \WP_Error ? $result : $this->response($result);
    }

    public function resource($request)
    {
        $result = $this->resources->get(
            (string) $request->get_param('type'),
            (string) $request->get_param('id')
        );
        return $result instanceof \WP_Error ? $result : $this->response($result);
    }

    public function logs($request): array
    {
        return $this->response($this->audit->recent((int) ($request->get_param('limit') ?: 100)));
    }

    public function upload($request)
    {
        if ($this->staging === null) {
            return new \WP_Error(
                'asm_private_storage_unavailable',
                'Prywatny magazyn ASM nie jest dostępny. Skonfiguruj ASM_PRIVATE_STORAGE poza katalogiem publicznym.',
                array('status' => 503)
            );
        }
        $files = (array) $request->get_file_params();
        if (isset($files['file']) && is_array($files['file'])) {
            $file = $files['file'];
            if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                return new \WP_Error('asm_upload_failed', 'PHP odrzucił przesłany plik.', array('status' => 400));
            }
            $result = $this->staging->stageFile((string) $file['tmp_name'], (string) $file['name']);
            return $result instanceof \WP_Error ? $result : $this->response($result);
        }

        $url = (string) $request->get_param('url');
        if ($url === '') {
            return new \WP_Error('asm_upload_missing', 'Prześlij plik albo podaj adres HTTPS.', array('status' => 400));
        }
        $bytes = (new RemoteFetcher())->fetch($url, 20971520);
        if ($bytes instanceof \WP_Error) {
            return $bytes;
        }
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $result = $this->staging->stageBytes($bytes, $name);
        return $result instanceof \WP_Error ? $result : $this->response($result);
    }

    private function operationResponse(Operation $operation): array
    {
        $data = $operation->toArray();
        $data['change'] = AuditStore::redact($data['change']);
        $data['snapshot'] = AuditStore::redact($data['snapshot']);
        // Kopia opcji ma wartość pod kluczem „value”: ukrywamy ją według nazwy opcji, jak przy odczycie.
        if (strpos((string) $operation->action(), 'option.') === 0 && is_array($data['snapshot']) && array_key_exists('value', $data['snapshot'])) {
            $data['snapshot']['value'] = \ASM\Resources\SecretDetector::publicValue((string) ($operation->target()['id'] ?? ''), $data['snapshot']['value']);
        }
        return $this->response($data, $operation->id());
    }

    /** @param mixed $data */
    private function response($data, ?string $operationId = null): array
    {
        return array(
            'data' => $data,
            'meta' => array('timestamp_gmt' => gmdate('c')),
            'request_id' => $this->requestId(),
            'operation_id' => $operationId,
        );
    }

    private function error(\Throwable $error, string $code, int $status = 500): \WP_Error
    {
        if ($error instanceof OperationFailure) {
            return new \WP_Error($error->operationCode(), $error->getMessage(), array('status' => 409));
        }
        return new \WP_Error($code, $error->getMessage(), array('status' => $status));
    }

    private function requestId(): string
    {
        return bin2hex(random_bytes(12));
    }
}
