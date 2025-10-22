<?php
// src/AliyunOssAdapter.php
namespace Sessel\ThinkphpOssFilesystem\Adapter;

use AlibabaCloud\Oss\V2\Client;
use AlibabaCloud\Oss\V2\Utils;
use AlibabaCloud\Oss\V2\Models\PutObjectRequest;
use AlibabaCloud\Oss\V2\Models\GetObjectRequest;
use AlibabaCloud\Oss\V2\Models\DeleteObjectRequest;
use AlibabaCloud\Oss\V2\Models\ListObjectsV2Request;
use AlibabaCloud\Oss\V2\Models\CopyObjectRequest;
use AlibabaCloud\Oss\V2\Models\GetObjectAclRequest;
use AlibabaCloud\Oss\V2\Models\HeadObjectRequest;
use AlibabaCloud\Oss\V2\Models\ObjectAclRequest;
use AlibabaCloud\Oss\V2\Models\PutObjectAclRequest;
use AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider;
use AlibabaCloud\Oss\V2\Config as AliyunOssConfig;
use AlibabaCloud\SDK\Sts\V20150401\Sts;
use AlibabaCloud\Tea\Exception\TeaError;
use Darabonba\OpenApi\Models\Config as ModelConfig;
use DateTimeInterface;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\Flysystem\Visibility;
use Sessel\ThinkphpOssFilesystem\HandleException;

class AliyunAdapter implements FilesystemAdapter, PublicUrlGenerator, TemporaryUrlGenerator
{
    use HandleException;

    protected Client $client;
    protected array $config;
    protected PathPrefixer $prefixer;

    public function __construct(array $config)
    {
        $credentials = new StaticCredentialsProvider(
            $config['access_key_id'],
            $config['access_key_secret'],
            $config['security_token'] ?? null,
        );

        $config['credentials'] = $credentials;
        $cfg = new AliyunOssConfig(
            region: $config['region'],
            endpoint: $config['endpoint'],
            credentialsProvider: $credentials,
            signatureVersion: $config['signatureVersion'] ?? null,
            disableSSL: $config['disableSSL'] ?? null,
            insecureSkipVerify: $config['insecureSkipVerify'] ?? null,
            connectTimeout: $config['connectTimeout'] ?? null,
            readwriteTimeout: $config['readwriteTimeout'] ?? null,
            proxyHost: $config['proxyHost'] ?? null,
            useDualStackEndpoint: $config['useDualStackEndpoint'] ?? null,
            useAccelerateEndpoint: $config['useAccelerateEndpoint'] ?? null,
            useInternalEndpoint: $config['useInternalEndpoint'] ?? null,
            useCname: $config['useCname'] ?? null,
            usePathStyle: $config['usePathStyle'] ?? null,
            retryMaxAttempts: $config['retryMaxAttempts'] ?? null,
            retryer: $config['retryer'] ?? null,
            userAgent: $config['userAgent'] ?? null,
            additionalHeaders: $config['additionalHeaders'] ?? null,
            cloudBoxId: $config['cloudBoxId'] ?? null,
            enableAutoDetectCloudBoxId: $config['enableAutoDetectCloudBoxId'] ?? null,
        );
        $this->client = new Client($cfg);

        $this->config = $config;
        $this->prefixer = new PathPrefixer($config['prefix']);
    }

    public function fileExists(string $path): bool
    {
        try {
            $request = new HeadObjectRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $this->client->headObject($request);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function directoryExists(string $path): bool
    {
        return $this->fileExists(rtrim($path, '/') . '/');
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $request = new PutObjectRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $request->body = Utils::streamFor($contents);

            // 设置可见性
            if ($visibility = $config->get('visibility')) {
                $request->acl = $this->visibilityToAcl($visibility);
            }

            // 设置内容类型
            if ($contentType = $config->get('mimetype')) {
                $request->contentType = $contentType;
            }

            // 设置缓存控制
            if ($cacheControl = $config->get('CacheControl')) {
                $request->cacheControl = $cacheControl;
            }

            // 设置内容处置
            if ($contentDisposition = $config->get('ContentDisposition')) {
                $request->contentDisposition = $contentDisposition;
            }

            // 设置自定义元数据
            if ($metadata = $config->get('metadata')) {
                $request->metadata = $metadata;
            }

            $this->client->putObject($request);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToWriteFile("Unable to write file at path: {$path}. " . $e->getMessage());
        }
    }

    public function writeStream(string $path, $resource, Config $config): void
    {
        $contents = stream_get_contents($resource);
        if (is_resource($resource)) {
            fclose($resource);
        }
        $this->write($path, $contents, $config);
    }

    public function read(string $path): string
    {
        try {
            $request = new GetObjectRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $result = $this->client->getObject($request);
            return $result->body ?? '';
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToReadFile("Unable to read file at path: {$path}. " . $e->getMessage());
        }
    }

    public function readStream(string $path)
    {
        $content = $this->read($path);
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            $e = new UnableToReadFile("Unable to create stream for path: {$path}");
            $this->handleException($e, $path);
            throw $e;
        }
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $request = new DeleteObjectRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $this->client->deleteObject($request);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToDeleteFile("Unable to delete file at path: {$path}. " . $e->getMessage());
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $prefix = $this->prefixer->prefixPath(rtrim($path, '/') . '/');
            $this->deleteAllObjects($prefix);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToDeleteDirectory("Unable to delete directory at path: {$path}. " . $e->getMessage());
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->write(rtrim($path, '/') . '/', '', $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $request = new PutObjectAclRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $request->acl = $this->visibilityToAcl($visibility);
            $this->client->putObjectAcl($request);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToSetVisibility("Unable to set visibility for path: {$path}. " . $e->getMessage());
        }
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $request = new GetObjectAclRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $result = $this->client->getObjectAcl($request);

            $visibility = $this->aclToVisibility($result->acl ?? 'private');

            return new FileAttributes($path, null, $visibility);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToRetrieveMetadata("Unable to retrieve visibility for path: {$path}. " . $e->getMessage());
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $request = new HeadObjectRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $result = $this->client->headObject($request);

            return new FileAttributes($path, null, null, null, $result->contentType);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToRetrieveMetadata("Unable to retrieve mime type for path: {$path}. " . $e->getMessage());
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            $request = new HeadObjectRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $result = $this->client->headObject($request);

            $timestamp = null;
            if ($result->lastModified) {
                $timestamp = $result->lastModified->getTimestamp();
            }

            return new FileAttributes($path, null, null, $timestamp);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToRetrieveMetadata("Unable to retrieve last modified for path: {$path}. " . $e->getMessage());
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            $request = new HeadObjectRequest($this->config['bucket'], $this->prefixer->prefixPath($path));
            $result = $this->client->headObject($request);

            return new FileAttributes($path, $result->contentLength);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToRetrieveMetadata("Unable to retrieve file size for path: {$path}. " . $e->getMessage());
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        try {
            $prefix = $this->prefixer->prefixPath($path);
            $prefix = ltrim($prefix, '/');

            if ($prefix && !str_ends_with($prefix, '/')) {
                $prefix .= '/';
            }

            $request = new ListObjectsV2Request($this->config['bucket']);
            $request->prefix = $prefix;
            $request->maxKeys = 1000;

            if (!$deep) {
                $request->delimiter = '/';
            }

            do {
                $result = $this->client->listObjectsV2($request);

                // 处理文件
                if (isset($result->contents)) {
                    foreach ($result->contents as $object) {
                        $path = $this->prefixer->stripPrefix($object->key);
                        if ($path !== '' && !str_ends_with($path, '/')) {
                            yield new FileAttributes(
                                $path,
                                $object->size,
                                null,
                                $object->lastModified->getTimestamp(),
                                null,
                                ['etag' => trim($object->etag, '"')]
                            );
                        }
                    }
                }

                // 处理目录（只在非深度遍历时）
                if (!$deep && isset($result->commonPrefixes)) {
                    foreach ($result->commonPrefixes as $prefix) {
                        $path = $this->prefixer->stripPrefix(rtrim($prefix->prefix, '/'));
                        if ($path !== '') {
                            yield new DirectoryAttributes($path);
                        }
                    }
                }

                // 处理分页
                if ($result->isTruncated) {
                    $request->continuationToken = $result->nextContinuationToken;
                } else {
                    break;
                }

            } while ($result->isTruncated);

        } catch (\Exception $e) {
            $this->handleException($e, $path);
            // 如果目录不存在或其他错误，返回空结果
            return;
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (\Exception $e) {
            throw new UnableToMoveFile("Unable to move file from {$source} to {$destination}. " . $e->getMessage());
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $sourceKey = $this->prefixer->prefixPath($source);
            $destinationKey = $this->prefixer->prefixPath($destination);

            $request = new CopyObjectRequest(
                $this->config['bucket'],
                $destinationKey
            );
            $request->sourceBucket = $this->config['bucket'];
            $request->sourceKey = $sourceKey;

            // 设置可见性
            if ($visibility = $config->get('visibility')) {
                $request->acl = $this->visibilityToAcl($visibility);
            }

            $this->client->copyObject($request);
        } catch (\Exception $e) {
            throw new UnableToCopyFile("Unable to copy file from {$source} to {$destination}. " . $e->getMessage());
        }
    }

    protected function deleteAllObjects(string $prefix): void
    {
        $request = new ListObjectsV2Request($this->config['bucket']);
        $request->prefix = $prefix;
        $request->maxKeys = 1000;

        do {
            $result = $this->client->listObjectsV2($request);

            if (isset($result->contents)) {
                foreach ($result->contents as $object) {
                    $deleteRequest = new DeleteObjectRequest($this->config['bucket'], $object->key);
                    $this->client->deleteObject($deleteRequest);
                }
            }

            if ($result->isTruncated) {
                $request->continuationToken = $result->nextContinuationToken;
            } else {
                break;
            }

        } while ($result->isTruncated);
    }

    protected function visibilityToAcl(string $visibility): string
    {
        return $visibility === Visibility::PUBLIC ? 'public-read' : 'private';
    }

    protected function aclToVisibility(string $acl): string
    {
        return in_array($acl, ['public-read', 'public-read-write']) ? Visibility::PUBLIC : Visibility::PRIVATE;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 获取文件的临时访问URL
     */
    public function getTemporaryUrl(string $path, DateTimeInterface $expiration, Config $config): string
    {
        if($config->get('with_prefix', true)){
            $key = $this->prefixer->prefixPath($path);
        }
        // 创建GetObjectRequest对象，用于下载对象
        $request = new GetObjectRequest($this->config['bucket'], $key);

        // 调用presign方法生成预签名URL
        $result = $this->client->presign($request, ['expiration' => $expiration]);
        return $result->url;
    }

    public function publicUrl(string $path, Config $config): string
    {
        if($config->get('with_prefix', true)){
            $path = $this->prefixer->prefixPath($path);
        }
        return $this->getCdnDomain() . '/' . ltrim($path, '/');
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        return $this->getTemporaryUrl($path, $expiresAt, $config);
    }

    public function getEndpoint(): string
    {
        if ($this->config['endpoint']) {
            return $this->config['endpoint'];
        }

        $protocol = $this->config['use_ssl'] ? 'https' : 'http';
        return "{$protocol}://{$this->config['region']}.aliyuncs.com";
    }

    public function getBucketDomain(): string
    {
        if ($this->config['url']) {
            return rtrim($this->config['url'], '/');
        }

        $protocol = $this->config['use_ssl'] ? 'https' : 'http';
        return "{$protocol}://{$this->config['bucket']}.oss-{$this->config['region']}.aliyuncs.com";
    }

    public function getCdnDomain(): string
    {
        return $this->config['cdn_url'] ? rtrim($this->config['cdn_url'], '/') : $this->getBucketDomain();
    }

    // public function getStsToken(int $durationSeconds = 3600){
    //     // Constants
    //     $StsSignVersion = "1.0";
    //     $StsAPIVersion = "2015-04-01";
    //     $StsHost = "https://sts.aliyuncs.com/";
    //     $TimeFormat = "Y-m-d\TH:i:s\Z";
    //     $RespBodyFormat = "JSON";
    //     $PercentEncode = "%2F";
    //     $HTTPGet = "GET";
    //     $uuid = "Nonce-" . rand(1000, 9999);
    //     $currentTime = (new \DateTime('now', new \DateTimeZone('UTC')))->format($TimeFormat);
    //     $queryStr = http_build_query([
    //         "SignatureVersion" => $StsSignVersion,
    //         "Format" => $RespBodyFormat,
    //         "Timestamp" => $currentTime,
    //         "RoleArn" => $this->config['ram_role_arn'],
    //         "RoleSessionName" => "oss_test_sess",
    //         "AccessKeyId" => $this->config['access_key_id'],
    //         "SignatureMethod" => "HMAC-SHA1",
    //         "Version" => $StsAPIVersion,
    //         "Action" => "AssumeRole",
    //         "SignatureNonce" => $uuid,
    //         "DurationSeconds" => $durationSeconds
    //     ]);
    //     parse_str($queryStr, $queryParams);
    //     ksort($queryParams);
    //     $queryStr = http_build_query($queryParams);
    //     $strToSign = $HTTPGet . "&" . $PercentEncode . "&" . rawurlencode($queryStr);
    //     $signature = base64_encode(hash_hmac('sha1', $strToSign, $this->config['access_key_secret'] . "&", true));
    //     $assumeURL = $StsHost . "?" . $queryStr . "&Signature=" . urlencode($signature);
    //     $httpClient = new \GuzzleHttp\Client();
    //     try {
    //         $resp = $httpClient->get($assumeURL);
    //         $result = json_decode($resp->getBody()->getContents(), true);
    //         if (json_last_error() !== JSON_ERROR_NONE) {
    //             throw new \Exception("Failed to decode JSON response");
    //         }
    //         return $result;
    //     } catch (\Throwable $e) {
    //         throw $e;
    //     }
    // }

    /**
     * 获取STS令牌
     * alibabacloud/sts-20150401 1.1.5 版本存在 Error: Call to undefined method Darabonba\OpenApi\Utils::getEndpointRules() 错误, 固定1.1.4版本解决
     * @param durationSeconds 有效时长，单位：秒
     */
    public function sts(
        int $durationSeconds = 3600,
        ?string $roleSessionName = null,
        array $action = [],
        string $resource = '',
        array $conditions = [],
    ){
        if(empty($action)){
            $action = [
                "oss:PutObject", // 允许上传操作
                "oss:InitiateMultipartUpload", // 若支持分片上传，需添加此权限
                "oss:UploadPart",
                "oss:CompleteMultipartUpload"
            ];
        }
        // 限制操作的资源（整个Bucket或特定路径）
        if(empty($resource)){
            $resource = $this->config['prefix'] ? "acs:oss:*:*:{$this->config['bucket']}/*" : "acs:oss:*:*:{$this->config['bucket']}/{$this->config['prefix']}/*";
        }

        $policy = [
            "Version" => "1",
            "Statement" => [
                [
                    "Effect" => "Allow",
                    "Action" => $action,
                    "Resource" => $resource,
                ]
            ]
        ];
        if(!empty($conditions)){
            $policy['Condition'] = $conditions;
        }
        try {
            // 1. 初始化配置
            $config = new ModelConfig([
                "accessKeyId" => $this->config['access_key_id'],
                "accessKeySecret" => $this->config['access_key_secret'],
                "regionId" => $this->config['region']
            ]);

            // 2. 创建STS客户端
            $client = new Sts($config);

            // 3. 构造AssumeRole请求参数
            $assumeRoleRequest = new \AlibabaCloud\SDK\Sts\V20150401\Models\AssumeRoleRequest([
                "roleArn" => $this->config['ram_role_arn'],
                "roleSessionName" => $roleSessionName ?? 'testRoleSessionName',
                "durationSeconds" => $durationSeconds,
                //添加Policy限制权限（如文件大小、操作范围等）
                "policy" => json_encode($policy)
            ]);

            // 4. 调用AssumeRole接口获取临时凭证
            $response = $client->assumeRole($assumeRoleRequest);

            // 5. 解析响应结果
            $credentials = $response->body->credentials;
            return [
                'accessKeyId' => $credentials->accessKeyId,
                'accessKeySecret' => $credentials->accessKeySecret,
                'expiration' => $credentials->expiration,
                'securityToken' => $credentials->securityToken,
            ];
        } catch (\Exception $error) {
            // 错误处理
            if (!($error instanceof TeaError)) {
                $error = new TeaError([], $error->getMessage(), $error->getCode(), $error);
            }
            throw $error;
        }
    }

    /**
     * 表单直传
     * @param string path 要上传的路径
     * @param int maxSize 文件大小约束，默认10M
     * @param int minSize 文件大小约束，默认0
     * @param int durationSeconds 有效期
     * @param array contentType 文件类型约束
     * @param array contentType 文件类型约束
     * @param array extraCondition 额外的条件
     * @see https://help.aliyun.com/zh/oss/developer-reference/signature-version-4-recommend
     */
    public function formUploadV1(
        string $path,
        int $maxSize = 10485760, //
        int $minSize = 0,
        int $durationSeconds = 3600, //1h
        array $contentType = ["image/jpg", "image/png", "image/jpeg"],
        array $extraCondition = [],
    ){
        $expireTime = time() + $durationSeconds;
        $expiration = gmdate('Y-m-d\TH:i:s\Z', $expireTime);
        $bucket = $this->config['bucket'];
        $endpoint = $this->config['endpoint'];
        $accessKeyId = $this->config['access_key_id'];
        $accessKeySecret = $this->config['access_key_secret'];

        $dir = $this->prefixer->prefixPath($path);
        $condition = [
                ["eq",'$bucket', $this->config['bucket']],  // 限制上传到指定Bucket
                ["starts-with", '$key', $this->config['prefix']],  // 限制上传路径前缀
                ["content-length-range", $minSize, $maxSize],  // 限制文件大小范围
                ["in", '$content-type', $contentType], // 仅允许图片类型
        ];
        if($extraCondition){
            $condition = array_merge($condition, $extraCondition);
        }
        //构建Post Policy内容
        $policy = [
            "expiration" => $expiration,  // 过期时间
            "conditions" => $condition,
        ];

        //对Policy进行Base64编码
        $policyBase64 = base64_encode(json_encode($policy, JSON_UNESCAPED_SLASHES));

        //使用AccessKeySecret对Policy进行HMAC-SHA1签名
        $signature = base64_encode(hash_hmac('sha1', $policyBase64, $accessKeySecret, true));

        //生成客户端上传所需的参数
        $uploadParams = [
            'host' => "https://{$bucket}.{$endpoint}", // OSS上传地址
            'policy' => $policyBase64,
            'signature' => $signature,
            'accessKeyId' => $accessKeyId,
            'dir' => $dir, // 上传后的文件路径
            'expire' => $expireTime,
            'maxSize' => $maxSize,
        ];
        return $uploadParams;
    }

    /**
     * 使用STS表单直传
     * @param string path 要上传的路径
     * @param int maxSize 文件大小约束，默认10M
     * @param int minSize 文件大小约束，默认0
     * @param int durationSeconds 有效期
     * @param array contentType 文件类型约束
     * @param array contentType 文件类型约束
     * @param array extraCondition 额外的条件
     * @see https://help.aliyun.com/zh/oss/developer-reference/signature-version-4-recommend
     */
    public function formUploadV4(
        string $path,
        int $maxSize = 10485760, //
        int $minSize = 0,
        int $durationSeconds = 3600, //1h
        array $contentType = ["image/jpg", "image/png", "image/jpeg"],
        array $extraCondition = [],
        string $callbackUrl = '',
        bool $sts = false,
    ){
        if($sts){
            $tokenData = $this->sts($durationSeconds);
        }else{
            $expireTime = time() + $durationSeconds;
            $expiration = gmdate('Y-m-d\TH:i:s\Z', $expireTime);
            $tokenData = [
                'accessKeyId' => $this->config['access_key_id'],
                'accessKeySecret' => $this->config['access_key_secret'],
                'expiration' => $expiration,
                'securityToken' => '',
            ];
        }
        $bucket = $this->config['bucket'];
        $endpoint = $this->config['endpoint'];
        $tempAccessKeyId = $tokenData['accessKeyId'];
        $tempAccessKeySecret = $tokenData['accessKeySecret'];
        $securityToken = $tokenData['securityToken'];
        $expiration = $tokenData['expiration'];
        $now = time();
        $dtObj = gmdate('Ymd\THis\Z', $now);
        $dtObj1 = gmdate('Ymd', $now);
        $dir = $this->prefixer->prefixPath($path);
        $condition = [
                ["eq",'$bucket', $this->config['bucket']],  // 限制上传到指定Bucket
                ["starts-with", '$key', $this->config['prefix']],  // 限制上传路径前缀
                ["content-length-range", $minSize, $maxSize],  // 限制文件大小范围
                ["in", '$content-type', $contentType], // 仅允许图片类型
                ["x-oss-signature-version" => "OSS4-HMAC-SHA256"],
                ["x-oss-credential" => "{$tempAccessKeyId}/{$dtObj1}/{$this->config['region']}/oss/aliyun_v4_request"],
                ["x-oss-date" => $dtObj],
        ];
        if($sts){
            $condition[] = ["x-oss-security-token" => $securityToken];
        }
        if($extraCondition){
            $condition = array_merge($condition, $extraCondition);
        }
        //构建Post Policy内容
        $policy = [
            "expiration" => $expiration,  // 过期时间
            "conditions" => $condition,
        ];

        $policyStr = json_encode($policy);

        // 构造待签名字符串
        $stringToSign = base64_encode($policyStr);

        // 计算SigningKey
        $dateKey = self::hmacsha256(('aliyun_v4' . $tempAccessKeySecret), $dtObj1);
        $dateRegionKey = self::hmacsha256($dateKey, $this->config['region']);
        $dateRegionServiceKey = self::hmacsha256($dateRegionKey, 'oss');
        $signingKey = self::hmacsha256($dateRegionServiceKey, 'aliyun_v4_request');

        // 计算Signature
        $result = self::hmacsha256($signingKey, $stringToSign);
        $signature = bin2hex($result);

        $callback_param = array(
            'callbackUrl' => $callbackUrl,
            'callbackBody' => 'filename=${object}&size=${size}&mimeType=${mimeType}&height=${imageInfo.height}&width=${imageInfo.width}',
            'callbackBodyType' => "application/x-www-form-urlencoded"
        );
        $callback_string = json_encode($callback_param);

        $base64_callback_body = base64_encode($callback_string);

        // 返回签名数据
        $uploadParams = [
            'policy' => $stringToSign,
            'x_oss_signature_version' => "OSS4-HMAC-SHA256",
            'x_oss_credential' => "{$tempAccessKeyId}/{$dtObj1}/{$this->config['region']}/oss/aliyun_v4_request",
            'x_oss_date' => $dtObj,
            'signature' => $signature,
            'host' => "https://{$bucket}.{$endpoint}",
            'security_token' => $securityToken,
            'callback' => $base64_callback_body,
            'dir' => $dir,
            'expire' => $expiration,
            'maxSize' => $maxSize,
        ];
        return $uploadParams;
    }

    static function hmacsha256($key, $data) {
        return hash_hmac('sha256', $data, $key, true);
    }
}