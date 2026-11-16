<?php

declare(strict_types=1);

namespace ASM\Resources;

final class ResourceCatalog
{
    /** @var array<string,object> */
    private array $resources;

    /** @param array<string,object> $resources */
    public function __construct(array $resources)
    {
        $this->resources = $resources;
    }

    public function list(string $type, array $params)
    {
        if (! isset($this->resources[$type])) {
            return $this->unknown($type);
        }
        return $this->resources[$type]->list($params);
    }

    public function get(string $type, string $id)
    {
        if (! isset($this->resources[$type])) {
            return $this->unknown($type);
        }
        return $this->resources[$type]->get($id);
    }

    /** @return array<int,string> */
    public function types(): array
    {
        return array_keys($this->resources);
    }

    private function unknown(string $type): \WP_Error
    {
        return new \WP_Error(
            'asm_unknown_resource_type',
            'Nieznany typ zasobu.',
            array('status' => 400, 'resource_type' => $type)
        );
    }
}
