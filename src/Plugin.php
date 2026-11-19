<?php

declare(strict_types=1);

namespace ASM;

use ASM\Admin\AdminPage;
use ASM\Auth\ApplicationPasswordGate;
use ASM\Database\QueryPolicy;
use ASM\Filesystem\PathPolicy;
use ASM\Inventory\InventoryService;
use ASM\Media\StagingService;
use ASM\Media\UploadValidator;
use ASM\Migration\Migration;
use ASM\Operations\LockManager;
use ASM\Operations\OperationEngine;
use ASM\Operations\OperationRegistry;
use ASM\Resources\CommentResource;
use ASM\Resources\ComponentResource;
use ASM\Resources\ContentResource;
use ASM\Resources\CronResource;
use ASM\Resources\CoreResource;
use ASM\Resources\DatabaseResource;
use ASM\Resources\ElementorResource;
use ASM\Resources\FileResource;
use ASM\Resources\MenuResource;
use ASM\Resources\MediaResource;
use ASM\Resources\OptionResource;
use ASM\Resources\CacheResource;
use ASM\Resources\PopupResource;
use ASM\Resources\PostTermResource;
use ASM\Resources\TermCreateResource;
use ASM\Resources\ResourceCatalog;
use ASM\Resources\UserResource;
use ASM\Rest\ApiController;
use ASM\Rest\Router;
use ASM\Search\SearchService;
use ASM\Search\DatabaseSearchSource;
use ASM\Search\FileSearchSource;
use ASM\Search\WordPressSearchSource;
use ASM\Storage\AuditStore;
use ASM\Storage\DatabaseLockBackend;
use ASM\Storage\OperationStore;
use ASM\Storage\PrivateStorage;
use ASM\Popup\Popup;
use ASM\Storage\PayloadCipher;

final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        global $wpdb;
        if (get_option('asm_schema_version', '') !== ASM_VERSION) {
            Installer::activate();
        }
        $storage = self::privateStorage();
        $staging = $storage !== null
            ? new StagingService($storage, new UploadValidator(), self::storageSecret())
            : null;
        $paths = new PathPolicy(array(ABSPATH));
        $queryPolicy = new QueryPolicy(defined('DB_NAME') ? (string) DB_NAME : '');
        $registry = self::operationRegistry($staging, $storage, $paths, $queryPolicy, $wpdb);
        $store = new OperationStore($wpdb, new PayloadCipher(self::storageSecret()));
        $audit = new AuditStore($wpdb);
        $engine = new OperationEngine(
            $registry,
            $store,
            new LockManager(new DatabaseLockBackend($wpdb)),
            $audit
        );
        \ASM\MCP\NativeBridge::configure($engine);
        $resourceHandlers = array(
            'posts' => new ContentResource(),
            'options' => new OptionResource(),
            'users' => new UserResource(),
            'comments' => new CommentResource(),
            'cron' => new CronResource(),
            'menus' => new MenuResource(),
            'cache' => new CacheResource(),
            'core' => new CoreResource(),
            'popup' => new PopupResource(),
        );
        if ($staging !== null && $storage !== null) {
            $resourceHandlers['media'] = new MediaResource('update', $staging, $storage);
            $resourceHandlers['files'] = new FileResource('read', $paths, $storage);
            $resourceHandlers['database'] = new DatabaseResource($wpdb, $queryPolicy, $storage);
            $resourceHandlers['plugins'] = new ComponentResource('plugin', 'read', $storage);
            $resourceHandlers['themes'] = new ComponentResource('theme', 'read', $storage);
        }
        $resources = new ResourceCatalog($resourceHandlers);
        $search = new SearchService(array(
            'content' => new WordPressSearchSource('content'),
            'meta' => new WordPressSearchSource('meta'),
            'options' => new WordPressSearchSource('options'),
            'comments' => new WordPressSearchSource('comments'),
            'terms' => new WordPressSearchSource('terms'),
            'media' => new WordPressSearchSource('media'),
        ));
        if ($storage !== null) {
            $search = new SearchService(array(
                'content' => new WordPressSearchSource('content'),
                'meta' => new WordPressSearchSource('meta'),
                'options' => new WordPressSearchSource('options'),
                'comments' => new WordPressSearchSource('comments'),
                'terms' => new WordPressSearchSource('terms'),
                'media' => new WordPressSearchSource('media'),
                'files' => new FileSearchSource($paths),
                'database' => new DatabaseSearchSource($wpdb, $queryPolicy),
            ));
        }
        $controller = new ApiController(
            $engine,
            $registry,
            $resources,
            $search,
            new InventoryService(),
            $audit,
            $staging
        );
        $router = new Router(new ApplicationPasswordGate(), $controller);
        add_action('rest_api_init', array($router, 'registerRoutes'));
        add_action('asm_mcp_cleanup', static function () use ($wpdb, $storage): void {
            \ASM\Storage\Retention::run($wpdb, $storage);
        });
        Migration::run();
        (new Popup())->hooks();
        Updater::hooks();
        if (is_admin()) {
            (new AdminPage($audit, $storage !== null))->hooks();
        }
    }

    private static function operationRegistry(
        ?StagingService $staging,
        ?PrivateStorage $storage,
        PathPolicy $paths,
        QueryPolicy $queryPolicy,
        object $database
    ): OperationRegistry {
        $registry = new OperationRegistry();
        $registry->register('term.create', new TermCreateResource());
        $registry->register('post.category.move', new PostTermResource('category'));
        $registry->register('post.tags.append', new PostTermResource('post_tag'));
        $registry->register('post.tags.remove', new PostTermResource('post_tag', true));
        $registry->register('content.create', new ContentResource('create'));
        $registry->register('content.update', new ContentResource('update'));
        $registry->register('content.replace', new ContentResource('replace'));
        $registry->register('content.meta', new ContentResource('meta'));
        $registry->register('content.delete', new ContentResource('delete'));
        $registry->register('elementor.update', new ElementorResource());
        $registry->register('menu.create', new MenuResource('create'));
        $registry->register('menu.update', new MenuResource('update'));
        $registry->register('menu.delete', new MenuResource('delete'));
        $registry->register('option.update', new OptionResource('update'));
        $registry->register('option.replace_secret', new OptionResource('replace_secret'));
        $registry->register('option.delete', new OptionResource('delete'));
        $registry->register('user.create', new UserResource('create'));
        $registry->register('user.update', new UserResource('update'));
        $registry->register('user.change_password', new UserResource('password'));
        $registry->register('user.delete', new UserResource('delete'));
        $registry->register('comment.update', new CommentResource('update'));
        $registry->register('comment.delete', new CommentResource('delete'));
        $registry->register('cron.schedule', new CronResource('schedule'));
        $registry->register('cron.run', new CronResource('run'));
        $registry->register('cron.delete', new CronResource('delete'));
        $registry->register('cache.purge', new CacheResource());
        $registry->register('core.update', new CoreResource());
        $registry->register('popup.update', new PopupResource());
        if ($staging !== null && $storage !== null) {
            $registry->register('media.create', new MediaResource('create', $staging, $storage));
            $registry->register('media.update', new MediaResource('update', $staging, $storage));
            $registry->register('media.replace', new MediaResource('replace', $staging, $storage));
            $registry->register('media.delete', new MediaResource('delete', $staging, $storage));
            $registry->register('file.create', new FileResource('create', $paths, $storage));
            $registry->register('file.write', new FileResource('write', $paths, $storage));
            $registry->register('file.patch', new FileResource('patch', $paths, $storage));
            $registry->register('file.move', new FileResource('move', $paths, $storage));
            $registry->register('file.delete', new FileResource('delete', $paths, $storage));
            $registry->register('database.execute', new DatabaseResource($database, $queryPolicy, $storage));
            foreach (array('install', 'update', 'activate', 'deactivate', 'delete') as $action) {
                $registry->register('plugin.' . $action, new ComponentResource('plugin', $action, $storage));
            }
            foreach (array('install', 'update', 'activate', 'delete') as $action) {
                $registry->register('theme.' . $action, new ComponentResource('theme', $action, $storage));
            }
        }
        return $registry;
    }

    private static function privateStorage(): ?PrivateStorage
    {
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
            && $_SERVER['DOCUMENT_ROOT'] !== ''
            ? $_SERVER['DOCUMENT_ROOT']
            : ABSPATH;
        $root = defined('ASM_PRIVATE_STORAGE')
            ? (string) ASM_PRIVATE_STORAGE
            : dirname(rtrim($documentRoot, '/\\')) . DIRECTORY_SEPARATOR
                . '.asm-private-' . substr(hash('sha256', home_url('/')), 0, 12);
        try {
            return new PrivateStorage($root, $documentRoot);
        } catch (\Throwable $error) {
            return null;
        }
    }

    private static function storageSecret(): string
    {
        if (function_exists('wp_salt')) {
            return wp_salt('auth');
        }
        return defined('AUTH_KEY') ? (string) AUTH_KEY : hash('sha256', ABSPATH);
    }
}
