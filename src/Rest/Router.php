<?php

declare(strict_types=1);

namespace ASM\Rest;

use ASM\Auth\ApplicationPasswordGate;

final class Router
{
    private ApplicationPasswordGate $gate;
    private ?ApiController $controller;

    public function __construct(ApplicationPasswordGate $gate, ?ApiController $controller = null)
    {
        $this->gate = $gate;
        $this->controller = $controller;
    }

    public function registerRoutes(): void
    {
        $this->route('/ping', \WP_REST_Server::READABLE, 'site.read', 'ping');
        $this->route('/capabilities', \WP_REST_Server::READABLE, 'site.read', 'capabilities');
        $this->route('/inventory', \WP_REST_Server::READABLE, 'inventory.read', 'inventory');
        $this->route('/search', \WP_REST_Server::CREATABLE, 'search.read', 'search', Schema::searchArgs());
        $this->route(
            '/operations/preview',
            \WP_REST_Server::CREATABLE,
            'operation.execute',
            'preview',
            Schema::operationPreviewArgs()
        );
        $this->route(
            '/operations/(?P<id>[a-f0-9-]{36})/execute',
            \WP_REST_Server::CREATABLE,
            'operation.execute',
            'execute',
            Schema::idArgs()
        );
        $this->route(
            '/operations/(?P<id>[a-f0-9-]{36})',
            \WP_REST_Server::READABLE,
            'operation.read',
            'operation',
            Schema::idArgs()
        );
        $this->route(
            '/operations/(?P<id>[a-f0-9-]{36})/rollback',
            \WP_REST_Server::CREATABLE,
            'operation.rollback',
            'rollback',
            Schema::idArgs()
        );
        $this->route(
            '/resources/(?P<type>[a-z_]+)',
            \WP_REST_Server::READABLE,
            array($this, 'authorizeResource'),
            'resources',
            Schema::resourceArgs(false)
        );
        $this->route(
            '/resources/(?P<type>[a-z_]+)/(?P<id>[^/]+)',
            \WP_REST_Server::READABLE,
            array($this, 'authorizeResource'),
            'resource',
            Schema::resourceArgs(true)
        );
        $this->route('/uploads', \WP_REST_Server::CREATABLE, 'media.write', 'upload');
        $this->route('/logs', \WP_REST_Server::READABLE, 'logs.read', 'logs');
    }

    /** @param callable|string $callback */
    private function route(
        string $path,
        string $methods,
        $action,
        $callback,
        array $args = array()
    ): void {
        $resolvedCallback = is_string($callback) ? array($this, $callback) : $callback;

        register_rest_route(
            ASM_REST_NAMESPACE,
            $path,
            array(
                'methods' => $methods,
                'callback' => $resolvedCallback,
                'permission_callback' => function ($request = null) use ($action) {
                    if (is_callable($action)) {
                        return $action($request);
                    }
                    return $this->gate->authorize((string) $action);
                },
                'args' => $args,
            )
        );
    }

    /** @return array<string,mixed> */
    public function ping(): array
    {
        return array(
            'ok' => true,
            'plugin' => 'AI Site Manager',
            'version' => ASM_VERSION,
            'namespace' => ASM_REST_NAMESPACE,
        );
    }

    /** @return array<string,mixed> */
    public function capabilities(): array
    {
        return array(
            'full_control' => current_user_can('asm_full_control'),
            'manage_options' => current_user_can('manage_options'),
        );
    }

    public function inventory($request)
    {
        return $this->dispatch('inventory', $request);
    }

    public function search($request)
    {
        return $this->dispatch('search', $request);
    }

    public function preview($request)
    {
        return $this->dispatch('preview', $request);
    }

    public function execute($request)
    {
        return $this->dispatch('execute', $request);
    }

    public function operation($request)
    {
        return $this->dispatch('operation', $request);
    }

    public function rollback($request)
    {
        return $this->dispatch('rollback', $request);
    }

    public function resources($request)
    {
        return $this->dispatch('resources', $request);
    }

    public function resource($request)
    {
        return $this->dispatch('resource', $request);
    }

    public function logs($request)
    {
        return $this->dispatch('logs', $request);
    }

    public function upload($request)
    {
        return $this->dispatch('upload', $request);
    }

    public function notImplemented(): \WP_Error
    {
        return new \WP_Error(
            'asm_not_implemented',
            'Moduł jest jeszcze budowany.',
            array('status' => 501)
        );
    }

    private function dispatch(string $method, $request)
    {
        if ($this->controller === null) {
            return $this->notImplemented();
        }
        return $this->controller->{$method}($request);
    }

    public function authorizeResource($request)
    {
        $type = is_object($request) && method_exists($request, 'get_param')
            ? (string) $request->get_param('type')
            : '';
        $map = array(
            'posts' => 'content.read',
            'media' => 'media.read',
            'menus' => 'menu.read',
            'options' => 'options.read',
            'users' => 'users.read',
            'comments' => 'comments.read',
            'cron' => 'cron.read',
            'plugins' => 'plugins.read',
            'themes' => 'themes.read',
            'core' => 'core.update',
            'cache' => 'cache.read',
            'files' => 'file.read',
            'database' => 'database.read',
            'popup' => 'popup.read',
        );
        if (! isset($map[$type])) {
            return new \WP_Error('asm_unknown_resource_type', 'Nieznany typ zasobu.', array('status' => 400));
        }
        return $this->gate->authorize($map[$type]);
    }
}
