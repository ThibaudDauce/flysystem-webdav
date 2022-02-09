<?php

namespace Tests;

use Sabre\DAV\Client;
use League\Flysystem\FilesystemAdapter;
use ThibaudDauce\FlysystemWebdav\WebdavAdapter;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\PathPrefixer;

class WebdavAdapterTest extends FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $client = new Client([
            'baseUri' => getenv('TESTS_WEBDAV_BASE_URL'),
            'userName' => getenv('TESTS_WEBDAV_USERNAME'),
            'password' => getenv('TESTS_WEBDAV_PASSWORD'),
        ]);

        return new WebdavAdapter(
            $client,
            pathPrefixer: new PathPrefixer('/remote.php/webdav/webdav_tests/'),
            fakingVisibilityForTests: true,
        );
    }   
}