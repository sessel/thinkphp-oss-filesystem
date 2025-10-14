<?php

namespace Sessel\ThinkphpOssFilesystem;

use League\Flysystem\FilesystemException;
use RuntimeException;

class OssFilesystemException extends RuntimeException implements FilesystemException
{

}