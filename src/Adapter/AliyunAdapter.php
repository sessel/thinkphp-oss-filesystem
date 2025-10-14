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
use League\Flysystem\Visibility;
use Sessel\ThinkphpOssFilesystem\HandleException;

class AliyunAdapter implements FilesystemAdapter
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
     * 获取文件的公共访问URL
     */
    public function getUrl(string $path): string
    {
        $fullPath = $this->prefixer->prefixPath($path);
        return $this->getEndpoint() . '/' . ltrim($fullPath, '/');
    }

    /**
     * 获取文件的临时访问URL
     */
    public function getTemporaryUrl(string $path, int $expiration = 3600): string
    {
        $key = $this->prefixer->prefixPath($path);
        // 创建GetObjectRequest对象，用于下载对象
        $request = new GetObjectRequest($this->config['bucket'], $key);

        // 调用presign方法生成预签名URL
        $result = $this->client->presign($request);
        return $result->url;
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
        return "{$protocol}://{$this->config['bucket']}.{$this->config['region']}.aliyuncs.com";
    }

    public function getCdnDomain(): string
    {
        return $this->config['cdn_url'] ? rtrim($this->config['cdn_url'], '/') : $this->getBucketDomain();
    }

    public function getStsToken(){
        // Constants
        $StsSignVersion = "1.0";
        $StsAPIVersion = "2015-04-01";
        $StsHost = "https://sts.aliyuncs.com/";
        $TimeFormat = "Y-m-d\TH:i:s\Z";
        $RespBodyFormat = "JSON";
        $PercentEncode = "%2F";
        $HTTPGet = "GET";
        $uuid = "Nonce-" . rand(1000, 9999);
        $currentTime = (new \DateTime('now', new \DateTimeZone('UTC')))->format($TimeFormat);
        $queryStr = http_build_query([
            "SignatureVersion" => $StsSignVersion,
            "Format" => $RespBodyFormat,
            "Timestamp" => $currentTime,
            "RoleArn" => $this->config['ram_role_arn'],
            "RoleSessionName" => "oss_test_sess",
            "AccessKeyId" => $this->config['access_key_id'],
            "SignatureMethod" => "HMAC-SHA1",
            "Version" => $StsAPIVersion,
            "Action" => "AssumeRole",
            "SignatureNonce" => $uuid,
            "DurationSeconds" => 3600
        ]);
        parse_str($queryStr, $queryParams);
        ksort($queryParams);
        $queryStr = http_build_query($queryParams);
        $strToSign = $HTTPGet . "&" . $PercentEncode . "&" . rawurlencode($queryStr);
        $signature = base64_encode(hash_hmac('sha1', $strToSign, $this->config['access_key_secret'] . "&", true));
        $assumeURL = $StsHost . "?" . $queryStr . "&Signature=" . urlencode($signature);
        $httpClient = new \GuzzleHttp\Client();
        try {
            $resp = $httpClient->get($assumeURL);
            $result = json_decode($resp->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to decode JSON response");
            }
            return $result;
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}