<?php

namespace Sessel\ThinkphpOssFilesystem;

use BadMethodCallException;
use DateTime;

trait DriverExtend
{
    protected $methods = [];

    protected function validateConfig(): void
    {
        foreach ($this->required as $key) {
            if (empty($this->config[$key])) {
                throw new \InvalidArgumentException("Config key '{$key}' is required");
            }
        }
    }

    public function getConfig(null|string $key = null, mixed $default = null): mixed
    {
        if(empty($key)){
            return $this->config;
        }
        return $this->config[$key] ?? $default;
    }

    public function getAdapter(){
        return $this->adapter;
    }

    public function url(string $path, int $expires = 0, $config = []): string
    {
        if($expires > 0){
            $datetime = sprintf('+%d seconds', $expires);
            return $this->filesystem->temporaryUrl($path, new DateTime($datetime), $config);
        }
        return $this->filesystem->publicUrl($path, $config);
    }

    public function removePrefix(string $path): string
    {
        $prefix = $this->config['prefix'];
        if(empty($prefix)){
            return ltrim($path, '\\/');
        }
        if(strpos("{$prefix}/", $path) === 0){
            return str_replace("{$prefix}/", '', ltrim($path, '\\/'));
        }
        return $path;
    }

    public function prefixPath(string $path): string
    {
        return $this->config['prefix'] . ltrim($path, '\\/');
    }

    public function extend(string $method, callable $callback)
    {
        $this->methods[$method] = $callback;
        return $this;
    }

    public function __call($method, $parameters)
    {
        $callble = $this->methods[$method] ?? null;
        if($callble){
           return call_user_func($callble, ...$parameters);
        }
        return $this->filesystem->$method(...$parameters);
    }
}