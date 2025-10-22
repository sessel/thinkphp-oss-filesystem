<?php

namespace think\filesystem\driver;

use think\filesystem\Driver;
use Sessel\ThinkphpOssFilesystem\Adapter\AliyunAdapter;
use Sessel\ThinkphpOssFilesystem\DriverExtend;
use League\Flysystem\FilesystemAdapter;

class Aliyun extends Driver
{
    use DriverExtend;

    private $adapter;

    /**
     * 配置参数
     * @var array
     */
    protected $config = [
        'region' => '',
        'bucket' => '',
        'access_key_id' => '',
        'access_key_secret' => '',
        'security_token' => null,
        'endpoint' => null,
        'use_ssl' => true,
        'prefix' => '',
        'url' => '',
        'cdn_url' => '',
        'ram_role_arn' => null,
        'error_handler' => null,
    ];

    protected $required = ['bucket', 'access_key_id', 'access_key_secret', 'endpoint'];

    public function __construct(array $config)
    {

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
        return $this->adapter = new AliyunAdapter($this->config);
    }
}
