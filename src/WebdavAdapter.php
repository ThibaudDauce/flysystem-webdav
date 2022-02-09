<?php

namespace ThibaudDauce\FlysystemWebdav;

use Sabre\DAV\Client;
use League\Flysystem\Config;
use League\Flysystem\Visibility;
use League\Flysystem\PathPrefixer;
use Sabre\HTTP\ClientHttpException;
use League\Flysystem\FileAttributes;
use League\Flysystem\PathNormalizer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToWriteFile;
use Sabre\DAV\Xml\Property\ResourceType;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\WhitespacePathNormalizer;
use League\Flysystem\InvalidVisibilityProvided;

class WebdavAdapter implements FilesystemAdapter
{
    /** @var array<string, string> */
    private array $fakeVisibilitiesForTests = [];

    public function __construct(
        private Client $client,
        private ?PathNormalizer $pathNormalizer = null,
        private ?PathPrefixer $pathPrefixer = null,
        private bool $fakingVisibilityForTests = false,
    ) {
        $this->pathNormalizer ??= new WhitespacePathNormalizer;
        $this->pathPrefixer ??= new PathPrefixer(prefix: '');
    }

    /**
     * @throws FilesystemException
     * @throws UnableToCheckExistence
     */
    public function fileExists(string $path): bool
    {
        $location = $this->pathPrefixer->prefixPath($this->encodePath($path));

        try {
            $this->client->propFind($location, []);

            return true;
        } catch (ClientHttpException) {
            return false;
        }
    }

    /**
     * @throws FilesystemException
     * @throws UnableToCheckExistence
     */
    public function directoryExists(string $path): bool
    {
        $location = $this->pathPrefixer->prefixDirectoryPath($this->encodePath($path));

        try {
            $this->client->propFind($location, []);

            return true;
        } catch (ClientHttpException) {
            return false;
        }
    }

    /**
     * @throws UnableToWriteFile
     * @throws FilesystemException
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $directoryName = dirname($path);
        try {
            $this->createDirectory($directoryName, $config);
        } catch (UnableToCreateDirectory $e) {
            throw new UnableToWriteFile("Unable to create the directory {$directoryName}", previous: $e);
        }

        $location = $this->pathPrefixer->prefixPath($this->encodePath($path));
        $response = $this->client->request('PUT', $location, $contents);

        if ($response['statusCode'] >= 400) {
            $data = json_encode($response);
            throw new UnableToWriteFile("URL: {$location}, Server respond with {$data}");
        }

        if ($visibility = $config->get(Config::OPTION_VISIBILITY)) {
            $this->setVisibility($path, $visibility);
        }
    }

    /**
     * @param resource $contents
     *
     * @throws UnableToWriteFile
     * @throws FilesystemException
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $contents = stream_get_contents($contents);

        $this->write($path, $contents, $config);
    }

    /**
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function read(string $path): string
    {
        $location = $this->pathPrefixer->prefixPath($this->encodePath($path));

        $response = $this->client->request('GET', $location);

        if ($response['statusCode'] !== 200) {
            $data = json_encode($response);
            throw new UnableToReadFile("Server response was {$data}");
        }

        return $response['body'];
    }

    /**
     * @return resource
     *
     * @throws UnableToReadFile
     * @throws FilesystemException
     */
    public function readStream(string $path)
    {
        $contents = $this->read($path);

        $stream = fopen('php://memory','r+');
        fwrite($stream, $contents);
        rewind($stream);
        return $stream;
    }

    /**
     * @throws UnableToDeleteFile
     * @throws FilesystemException
     */
    public function delete(string $path): void
    {
        $location = $this->pathPrefixer->prefixPath($this->encodePath($path));

        $this->client->request('DELETE', $location);
    }

    /**
     * @throws UnableToDeleteDirectory
     * @throws FilesystemException
     */
    public function deleteDirectory(string $path): void
    {
        $location = $this->pathPrefixer->prefixPath($this->encodePath($path));

        $this->client->request('DELETE', $location);
    }

    /**
     * @throws UnableToCreateDirectory
     * @throws FilesystemException
     */
    public function createDirectory(string $path, Config $config): void
    {
        if ($this->pathNormalizer->normalizePath($path) === '' || $this->directoryExists($path)) {
            return;
        }

        $directoryName = dirname($path);
        try {
            $this->createDirectory($directoryName, $config);
        } catch (UnableToCreateDirectory $e) {
            throw new UnableToCreateDirectory("Unable to create the directory {$path} because impossible to create {$directoryName}", previous: $e);
        }

        $location = $this->pathPrefixer->prefixDirectoryPath($this->encodePath($path));

        $response = $this->client->request('MKCOL', $location);

        if ($response['statusCode'] !== 201) {
            $data = json_encode($response);
            throw new UnableToCreateDirectory("Impossible to create directory, server response is {$data}.");
        }
    }

    /**
     * @throws InvalidVisibilityProvided
     * @throws FilesystemException
     */
    public function setVisibility(string $path, string $visibility): void
    {
        if (! $this->fakingVisibilityForTests) {
            throw new UnableToSetVisibility("Webdav doesn't support visibilities.");
        }

        if (! $this->fileExists($path)) {
            throw new UnableToSetVisibility;
        }

        if ($visibility === Visibility::PUBLIC || $visibility === Visibility::PRIVATE) {
            $this->fakeVisibilitiesForTests[$path] = $visibility;
        } else {
            throw new InvalidVisibilityProvided;
        }
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    private function fileAttributes(string $path): FileAttributes
    {
        $location = $this->pathPrefixer->prefixDirectoryPath($this->encodePath($path));

        try {
            $response = $this->client->propFind($location, [], 0);

            if ($this->isDirectory($response)) {
                throw new UnableToRetrieveMetadata;
            }

            return new FileAttributes(
                $path,
                fileSize: $response['{DAV:}getcontentlength'] ?? null,
                mimeType: $response['{DAV:}getcontenttype'] ?? null,
                lastModified: isset($response['{DAV:}getlastmodified']) ? strtotime($response['{DAV:}getlastmodified']) : null,
                visibility: $this->fakingVisibilityForTests ? $this->fakeVisibilitiesForTests[$path] ?? Visibility::PUBLIC : null,
            );
        } catch (ClientHttpException $e) {
            throw new UnableToRetrieveMetadata(previous: $e);
        }
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function visibility(string $path): FileAttributes
    {
        if (! $this->fakingVisibilityForTests) {
            throw new UnableToRetrieveMetadata("Webdav doesn't support visibilities.");
        }

        return $this->fileAttributes($path);
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->fileAttributes($path);

        if (! $attributes->mimeType() || $attributes->mimeType() === 'application/octet-stream') {
            throw new UnableToRetrieveMetadata;
        }

        return $attributes;
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->fileAttributes($path);
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->fileAttributes($path);
    }

    /**
     * @return iterable<StorageAttributes>
     *
     * @throws FilesystemException
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $location = $this->pathPrefixer->prefixDirectoryPath($this->encodePath($path));
        $response = $this->client->propFind($location, [], $deep ? 'infinity' : 1);

        array_shift($response);

        foreach ($response as $path => $object) {
            $path = rawurldecode($path);
            if ($this->isDirectory($object)) {
                yield new DirectoryAttributes($this->pathPrefixer->stripDirectoryPrefix($path));
            } else {
                yield new FileAttributes($this->pathPrefixer->stripPrefix($path));
            }
        }
    }

    /**
     * @throws UnableToMoveFile
     * @throws FilesystemException
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $directoryName = dirname($destination);
        try {
            $this->createDirectory($directoryName, $config);
        } catch (UnableToCreateDirectory $e) {
            throw new UnableToMoveFile("Unable to create the directory {$directoryName}.", previous: $e);
        }

        $oldLocation = ltrim($this->pathPrefixer->prefixPath($this->encodePath($source)), '/');
        $newLocation = $this->client->getAbsoluteUrl($this->pathPrefixer->prefixPath($this->encodePath($destination)));

        $destination = $this->client->getAbsoluteUrl($newLocation);
        $response = $this->client->request('MOVE', "/{$oldLocation}/", null, [
            'Destination' => $destination,
        ]);

        if ($response['statusCode'] === 404) {
            throw new UnableToMoveFile("File {$source} doesn't exists.");
        }
    }

    /**
     * @throws UnableToCopyFile
     * @throws FilesystemException
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $directoryName = dirname($destination);
        try {
            $this->createDirectory($directoryName, $config);
        } catch (UnableToCreateDirectory $e) {
            throw new UnableToCopyFile("Unable to create the directory {$directoryName}.", previous: $e);
        }

        $oldLocation = ltrim($this->pathPrefixer->prefixPath($this->encodePath($source)), '/');
        $newLocation = $this->client->getAbsoluteUrl($this->pathPrefixer->prefixPath($this->encodePath($destination)));

        $destination = $this->client->getAbsoluteUrl($newLocation);
        $this->client->request('COPY', "/{$oldLocation}/", null, [
            'Destination' => $destination,
        ]);
    }

    /**
     * Encode a path as an URL
     */
    protected function encodePath(string $path): string
	{
		$parts = explode('/', $path);
        foreach ($parts as $i => $part) {
            $parts[$i] = rawurlencode($part);
        }
		return implode('/', $parts);
	}

    protected function isDirectory(array $object): bool
    {
        if (isset($object['{DAV:}resourcetype'])) {
            /** @var ResourceType $resourceType */
            $resourceType = $object['{DAV:}resourcetype'];
            return $resourceType->is('{DAV:}collection');
        }

        return isset($object['{DAV:}iscollection']) && $object['{DAV:}iscollection'] === '1';
    }
}