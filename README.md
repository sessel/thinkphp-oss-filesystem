# Volcengine Object Storage(TOS) filesystem for ThinkPHP
Thinkphp对象存储Filesystem扩展, 基于 `topthink/think-filesystem`

## 安装
```
# ThinkPHP 6.x
composer require sessel/thinkphp-oss-filesystem:^1.0

# ThinkPHP 8.x
composer require sessel/thinkphp-oss-filesystem:^2.0
```

## 配置
```
# config/filesystem.php
...
//阿里云OSS
'aliyun' => [
    'type' => 'aliyun',
    'region' => 'region',
    'bucket' => 'bucket',
    'access_key_id' => 'access_key_id',
    'access_key_secret' => 'access_key_secret',
    'security_token' => null,
    'endpoint' => 'endpoint',
    'use_ssl' => true,
    'prefix' => 'prefix',
    'url' => '',
    'cdn_url' => '',
    'ram_role_arn' => 'ram_role_arn',
    'error_handler' => null,
]
//火山云TOS
'vetos' => [
    'type' => 'vetos',
    'ak' => 'ak',
    'sk' => 'sk',
    'region' => 'region',
    'bucket' => 'bucket',
    'prefix' => 'prefix',
    'domain' => 'custom domain',
    'endpoint' => 'https://xxx/',
],
...
```

## 示例
```
use think\facade\Filesystem;
use GuzzleHttp\Psr7\Utils;

$disk = Filesystem::disk('aliyun');
// $gen = $disk->listContents('/');
// $files = [];
// foreach($gen as $file){
//     $files[] = $file;
// }
// halt($files);
// halt($disk->url('/php.png'));
// halt($disk->has('/php.png'));
// halt($disk->has('/php2.png'));
// halt($disk->copy('/php2.png', 'php3.png'));
// halt($disk->delete('/php3.png'));
// halt($disk->read('/test.txt'));
// halt($disk->createDirectory('test'));
// halt($disk->fileExists('php5.png'));
// halt($disk->write('test.txt', '222');
// $content = '';
// for ($i = 0; $i < 20000; $i++) {
//     $content .= uniqid();
// }
// $veTosAdapter = app('oss.volcengine', ['config' => config('filesystem.disks.oss')]);
// $veTosAdapter = $disk->getAdapter();
// halt($veTosAdapter->appendWrite('test_append2.txt', $content, new Config(['next_append_offset' => 260000])));
// halt($disk->visibility('test.txt'));
// halt($disk->fileSize('test_append.txt'));
// halt($disk->mimeType('test_append.txt'));
// halt($disk->lastModified('test_append.txt'));
// halt($disk->read('test_append.txt'));
// $readStream = $disk->readStream('test_append.txt');
// $writeStream = fopen(public_path('static') . 'test_append.txt', 'w+');
// Utils::copyToStream(Utils::streamFor($readStream), Utils::streamFor($writeStream));
```
