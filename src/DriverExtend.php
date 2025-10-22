<?php

namespace Sessel\ThinkphpOssFilesystem;

use DateTime;

trait DriverExtend
{
    protected $required = [];

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

    /**
     * 自定义处理异常
     */
    protected function handleException(\Exception $e, string $path = ''): void
    {
        if(!empty($this->config['error_handler']) && is_callable($this->config['error_handler'])){
            call_user_func($this->config['error_handler'], $e, $path);
        }
    }
}