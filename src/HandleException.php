<?php

namespace Sessel\ThinkphpOssFilesystem;


trait HandleException
{
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