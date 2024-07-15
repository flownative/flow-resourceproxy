<?php
declare(strict_types=1);

namespace Flownative\Flow\ResourceProxy;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Flow\ResourceManagement\Storage\StorageInterface;
use Neos\Flow\ResourceManagement\Storage\WritableFileSystemStorage;
use Neos\Flow\ResourceManagement\Storage\WritableStorageInterface;
use Neos\Utility\Files;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 * @Flow\Aspect
 */
class ResourceImportAspect
{
    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    protected Browser $browser;

    /**
     * @Flow\InjectConfiguration(path="storages", package="Flownative.Flow.ResourceProxy")
     */
    protected array $storagesSettings = [];

    /**
     * @Flow\InjectConfiguration(path="resource.storages", package="Neos.Flow")
     */
    protected array $flowStoragesSettings = [];

    public function initializeObject(): void
    {
        $this->browser = new Browser();
        $this->browser->setRequestEngine(new CurlEngine());
    }

    /**
     * @Flow\Around("within(Neos\Flow\ResourceManagement\Storage\StorageInterface) && method(.*->getStreamByResource())")
     * @param JoinPointInterface $joinPoint The current join point
     * @return resource|boolean The resource stream or false if the stream could not be obtained
     */
    public function importOnGetStreamByResource(JoinPointInterface $joinPoint)
    {
        /** @var StorageInterface $storage */
        $storage = $joinPoint->getProxy();
        /** @var PersistentResource $resource */
        $resource = $joinPoint->getMethodArgument('resource');

        $stream = $storage->getStreamByResource($resource);

        if ($stream === false) {
            if (!isset($this->storagesSettings[$storage->getName()])) {
                $this->logger->debug(sprintf('The storage "%s" is not configured for proxying, nothing to do.', $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
                return $joinPoint->getAdviceChain()->proceed($joinPoint);
            }

            if (!$storage instanceof WritableStorageInterface) {
                $this->logger->notice(sprintf('The storage "%s" is not writable. Skipping fetching & importing.', $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
                return $joinPoint->getAdviceChain()->proceed($joinPoint);
            }

            $this->logger->debug(sprintf('The resource "%s" (%s) is not available available in storage "%s", fetching & importing it.', $resource->getFilename(), $resource->getSha1(), $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
            $this->importRemoteResource($resource, $storage);
        }

        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }

    /**
     * The method getPublicPersistentResourceUri() returns a string which is the URI of the resource.
     * It may return that URI without checking the existence of the "files" being available. Thus we
     * need to check for that file here and "import" it if it is not available.
     *
     * @Flow\Around("within(Neos\Flow\ResourceManagement\Target\TargetInterface) && method(.*->getPublicPersistentResourceUri())")
     * @param JoinPointInterface $joinPoint The current join point
     * @return string
     */
    public function importOnGetPublicPersistentResourceUri(JoinPointInterface $joinPoint): string
    {
        /** @var PersistentResource $resource */
        $resource = $joinPoint->getMethodArgument('resource');
        $collectionName = $resource->getCollectionName();
        $collection = $this->resourceManager->getCollection($collectionName);
        if ($collection === null) {
            throw new \RuntimeException(sprintf('The collection "%s" does not exist.', $collectionName), 1639042195);
        }

        $storage = $collection->getStorage();
        if (!isset($this->storagesSettings[$storage->getName()])) {
            $this->logger->debug(sprintf('The storage "%s" is not configured for proxying, nothing to do.', $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        if ($storage->getStreamByResource($resource)) {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        if (!$storage instanceof WritableStorageInterface) {
            $this->logger->notice(sprintf('The storage "%s" is not writable. Skipping fetching & importing.', $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $this->logger->debug(sprintf('The resource "%s" (%s) is not available available in storage "%s", fetching & importing it.', $resource->getFilename(), $resource->getSha1(), $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
        $this->importRemoteResource($resource, $storage);

        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }

    private function importRemoteResource(PersistentResource $resource, WritableStorageInterface $storage): void
    {
        if ($storage instanceof WritableStorageInterface) {
            $content = $this->getRemoteResource($resource, $storage);
            if ($content === false) {
                $this->logger->notice(sprintf('Could not fetch resource data for "%s".', $resource->getSha1()), LogEnvironment::fromMethodName(__METHOD__));
                return;
            }

            $storage->importResourceFromContent($content, $resource->getCollectionName());
            $this->logger->notice(sprintf('Imported resource data "%s" (%s) into storage "%s"', $resource->getFilename(), $resource->getSha1(), $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
        } else {
            $this->logger->notice(sprintf('The type of storage "%s" is not supported. Skipping fetching & importing.', $storage->getName()), LogEnvironment::fromMethodName(__METHOD__));
        }
    }

    private function getRemoteResource(PersistentResource $resource, StorageInterface $storage)
    {
        $subdivideHashPathSegment = $this->storagesSettings[$storage->getName()]['subdivideHashPathSegment'] ?? false;
        $remoteSourceBaseUri = $this->storagesSettings[$storage->getName()]['remoteSourceBaseUri'];
        $remoteUri = sprintf(
            '%s/%s',
            rtrim($remoteSourceBaseUri, '/'),
            $this->encodeRelativePathAndFilenameForUri(
                $this->getRelativePublicationPathAndFilename($resource, $subdivideHashPathSegment)
            )
        );
        $this->logger->debug(sprintf('Fetching remote resource "%s"', $remoteUri), LogEnvironment::fromMethodName(__METHOD__));

        $response = $this->browser->request(
            $remoteUri
        );

        if ($response->getStatusCode() !== 200) {
            $this->logger->debug(sprintf('Error fetching remote resource "%s": %s', $remoteUri, $response->getStatusCode()), LogEnvironment::fromMethodName(__METHOD__));
            return false;
        }

        return $response->getBody()->getContents();
    }

    /**
     * Determines and returns the relative path and filename for the given Storage Object or PersistentResource. If the given
     * object represents a persistent resource, its own relative publication path will be empty. If the given object
     * represents a static resources, it will contain a relative path.
     *
     * @param PersistentResource $resource
     * @param bool $subdivideHashPathSegment
     * @return string The relative path and filename, for example "c/8/2/8/c828d0f88ce197be1aff7cc2e5e86b1244241ac6/MyPicture.jpg" (if subdivideHashPathSegment is on) or
     *     "c828d0f88ce197be1aff7cc2e5e86b1244241ac6/MyPicture.jpg" (if it's off)
     */
    private function getRelativePublicationPathAndFilename(PersistentResource $resource, bool $subdivideHashPathSegment): string
    {
        if ($resource->getRelativePublicationPath() !== '') {
            $pathAndFilename = $resource->getRelativePublicationPath() . $resource->getFilename();
        } elseif ($subdivideHashPathSegment) {
            $sha1Hash = $resource->getSha1();
            $pathAndFilename = $sha1Hash[0] . '/' . $sha1Hash[1] . '/' . $sha1Hash[2] . '/' . $sha1Hash[3] . '/' . $sha1Hash . '/' . $resource->getFilename();
        } else {
            $pathAndFilename = $resource->getSha1() . '/' . $resource->getFilename();
        }
        return $pathAndFilename;
    }

    /**
     * Applies rawurlencode() to all path segments of the given $relativePathAndFilename
     */
    private function encodeRelativePathAndFilenameForUri(string $relativePathAndFilename): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $relativePathAndFilename)));
    }
}
