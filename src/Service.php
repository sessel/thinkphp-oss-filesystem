<?php
namespace Sessel\ThinkphpOssFilesystem;

use think\Service AS BaseService;
use Sessel\ThinkphpOssFilesystem\Adapter\VolcengineAdapter;

class Service extends BaseService
{
    public function register()
    {
         $this->app->bind('oss.volcengine', function($config){
            return new VolcengineAdapter($config);
         });
    }
}
