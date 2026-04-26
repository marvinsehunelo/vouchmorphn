<?php

namespace VouchMorph\Core;

class Container
{
    private array $instances = [];
    
    public function set(string $id, $instance): void
    {
        $this->instances[$id] = $instance;
    }
    
    public function get(string $id)
    {
        if (!isset($this->instances[$id])) {
            throw new \Exception("Service not found: $id");
        }
        return $this->instances[$id];
    }
    
    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }
}
