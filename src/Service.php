<?php
namespace Sessel\ThinkphpOssFilesystem;

use think\Service AS BaseService;
use Sessel\ThinkphpOssFilesystem\Adapter\VolcengineAdapter;
use Sessel\ThinkphpOssFilesystem\Adapter\AliyunAdapter;

class Service extends BaseService
{
    public function register()
    {
         $this->app->bind('oss.aliyun', function($config){
            return new AliyunAdapter($config);
         });
         $this->app->bind('oss.volcengine', function($config){
            return new VolcengineAdapter($config);
         });
    }
}
