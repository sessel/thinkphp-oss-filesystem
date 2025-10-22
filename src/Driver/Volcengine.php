<?php

namespace think\filesystem\driver;

use DateTime;
use League\Flysystem\FilesystemAdapter;
use think\filesystem\Driver;
use Sessel\ThinkphpOssFilesystem\Adapter\VolcengineAdapter;
use Sessel\ThinkphpOssFilesystem\HandleException;

class Volcengine extends Driver
{
    use HandleException;

    private $adapter;

    /**
     * 配置参数
     * @var array
     */
    protected $config = [
        'ak' => '',
        'sk' => '',
        'region' => '',
        'bucket' => '',
        'prefix' => '',
        'domain' => '',
        'endpoint' => '',
        'connection_timeout' => 3000,
        'socket_timeout' => 3000,
        'max_retries' => 3,
        'error_handler' => null,
    ];

    public function __construct(array $config)
    {
        //禁用volcengine/ve-tos-php-sdk代码导致的E_DEPRECATED错误
        error_reporting(error_reporting() & ~E_DEPRECATED);

        $this->config = array_merge($this->config, $config);
        $this->validateConfig();
        $this->adapter = $this->createAdapter();
        $this->filesystem = $this->createFilesystem($this->adapter);
    }

    /**
     * 创建适配器
     * @return FilesystemAdapter
     */
    protected function createAdapter(): FilesystemAdapter
    {
        // 合并默认配置
        $adapter = new VolcengineAdapter($this->config);
        return $adapter;
    }

    public function url(string $path, int $expires = 0, $config = []): string
    {
        if($expires > 0){
            $datetime = sprintf('+%d seconds', $expires);
            return $this->filesystem->temporaryUrl($path, new DateTime($datetime), $config);
        }
        return $this->filesystem->publicUrl($path);
    }

    public function __call($method, $parameters)
    {
        return $this->filesystem->$method(...$parameters);
    }

    public function getAdapter(){
        return $this->adapter;
    }

    protected function validateConfig(): void
    {
        $required = ['ak', 'sk', 'region', 'bucket'];
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \InvalidArgumentException("Config key '{$key}' is required");
            }
        }
    }
}
