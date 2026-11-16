<?php

declare(strict_types=1);

use ASM\Auth\ApplicationPasswordGate;
use ASM\Inventory\InventoryService;
use ASM\Operations\OperationEngine;
use ASM\Resources\ResourceCatalog;
use ASM\Rest\ApiController;
use ASM\Rest\Router;
use ASM\Search\SearchService;
use ASM\Storage\AuditStore;
use ASM\Storage\OperationStore;

require __DIR__ . '/fixture.php';

define('ASM_REST_NAMESPACE', 'asm/v2');

class WP_REST_Server
{
    public const READABLE = 'GET';
    public const CREATABLE = 'POST';
}

class WP_Error
{
    public function __construct(public string $code, public string $message, public array $data) {}
}

function register_rest_route(string $namespace, string $path, array $route): void
{
    $GLOBALS['routes'][$namespace . $path] = $route;
}

function is_user_logged_in(): bool { return $GLOBALS['loggedIn']; }
function get_current_user_id(): int { return 19; }

$database = new class {
    public array $operations = [];
    public array $keys = [];

    public function prepare(string $sql, string ...$args): array { return [$sql, $args]; }
    public function replace(string $table, array $row, array $formats): int
    {
        $this->operations[$row['id']] = $row['payload'];
        return 1;
    }
    public function insert(string $table, array $row, array $formats): int { return 1; }
    public function get_var(array $query): ?string
    {
        [$sql, [$arg]] = $query;
        return str_contains($sql, 'asm_idempotency') ? ($this->keys[$arg] ?? null) : ($this->operations[$arg] ?? null);
    }
    public function query(array $query): int
    {
        [, [$key, $id]] = $query;
        if (isset($this->keys[$key])) {
            return 0;
        }
        $this->keys[$key] = $id;
        return 1;
    }
};

$registry = taxonomyRegistry($database);
$audit = new AuditStore($database);
$engine = new OperationEngine($registry, new OperationStore($database));
$controller = new ApiController($engine, $registry, new ResourceCatalog([]), new SearchService([]), new InventoryService(), $audit);
$router = new Router(new ApplicationPasswordGate(), $controller);
$router->registerRoutes();

function rest(string $method, string $path, array $params = [], string $key = ''): array|WP_Error
{
    $route = $GLOBALS['routes']['asm/v2' . $path];
    check($route['methods'] === $method, 'Unexpected REST method');
    $request = new class($params, $key) {
        public function __construct(private array $params, private string $key) {}
        public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
        public function get_header(string $name): string { return $name === 'Idempotency-Key' ? $this->key : ''; }
        public function get_json_params(): array { return $this->params; }
    };
    $access = $route['permission_callback']($request);
    return $access === true ? $route['callback']($request) : $access;
}

$input = [
    'action' => 'term.create',
    'target' => ['type' => 'terms', 'id' => 'post_tag'],
    'expected' => ['exists' => false],
    'change' => ['name' => 'Tag REST', 'slug' => 'tag-rest'],
];
$loggedIn = false;
check(rest('POST', '/operations/preview', $input)->data['status'] === 401, 'Anonymous REST write must be rejected');
$loggedIn = true;
$fullControl = false;
check(rest('POST', '/operations/preview', $input)->data['status'] === 403, 'REST write must require full control');
$fullControl = true;

$preview = rest('POST', '/operations/preview', $input);
$operationId = $preview['operation_id'];
check($preview['data']['status'] === 'previewed' && $terms === [], 'Preview must register term.create without writing');
$path = '/operations/(?P<id>[a-f0-9-]{36})';
$verified = rest('POST', $path . '/execute', ['id' => $operationId], 'test-term-create');
check($verified['data']['status'] === 'verified' && count($terms['post_tag']) === 1, 'REST execute must create exactly one tag');
$retry = rest('POST', $path . '/execute', ['id' => $operationId], 'test-term-create');
check($retry['data']['target'] === $verified['data']['target'] && count($terms['post_tag']) === 1, 'Execute replay must preserve the original tag');

$legacy = json_decode($database->operations[$operationId], true, 512, JSON_THROW_ON_ERROR);
unset($legacy['guarded_rollback'], $legacy['after_hash']);
$database->operations[$operationId] = json_encode($legacy, JSON_THROW_ON_ERROR);
$status = rest('GET', $path, ['id' => $operationId]);
check($status['operation_id'] === $operationId && $status['data']['status'] === 'verified', 'ASM 2.0.1 history must stay readable in ASM 2.2.0');
$rolledBack = rest('POST', $path . '/rollback', ['id' => $operationId]);
check($rolledBack['data']['status'] === 'rolled_back' && $terms['post_tag'] === [], 'REST rollback must restore the ASM 2.0.1 term snapshot');

echo "PASS: REST authorization, preview, execute replay, history and rollback\n";
