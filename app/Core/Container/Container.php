<?php
declare(strict_types=1);

namespace Nyx\Core\Container;

use ReflectionClass;
use ReflectionParameter;

final class Container
{
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var array<string, callable> */
    private array $bindings = [];

    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
    }

    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    public function make(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->bindings[$id])) {
            return ($this->bindings[$id])($this);
        }
        if (!class_exists($id)) {
            throw new \RuntimeException("Cannot resolve [{$id}]");
        }
        $ref = new ReflectionClass($id);
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return new $id();
        }
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $args[] = $this->resolveParam($param);
        }
        return $ref->newInstanceArgs($args);
    }

    private function resolveParam(ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        if ($type && !$type->isBuiltin()) {
            return $this->make($type->getName());
        }
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }
        throw new \RuntimeException('Cannot resolve parameter $' . $param->getName());
    }
}
