<?php

namespace think\filesystem\driver;

use DateTime;
use League\Flysystem\FilesystemAdapter;
use think\filesystem\Driver;
use Sessel\ThinkphpOssFilesystem\Adapter\VolcengineAdapter;
use Sessel\ThinkphpOssFilesystem\DriverExtend;

class Volcengine extends Driver
{
    use DriverExtend;

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

    protected $required = ['ak', 'sk', 'region', 'bucket'];

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

    public function __call($method, $parameters)
    {
        return $this->filesystem->$method(...$parameters);
    }
}
