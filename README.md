[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/flow-resourceproxy.svg)](https://packagist.org/packages/flownative/flow-resourceproxy)
[![Packagist](https://img.shields.io/packagist/dm/flownative/flow-resourceproxy)](https://packagist.org/packages/flownative/flow-resourceproxy)
[![Maintenance level: Love](https://img.shields.io/badge/maintenance-%E2%99%A1%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# Flownative.Flow.ResourceProxy

## Description

This [Flow](https://flow.neos.io) allows to transparently import resource data
from other systems  into Flow. It does this by providing an implementation that
tries to fetch  missing resources from a remote source. When a resource can be
fetched, it is "imported" and available as usual, otherwise the system behaves
as usual when a resource is missing.

Because [Neos CMS](https://www.neos.io) is using Flow's resource management under
the hood, this also works nicely for assets in Neos.

This is mostly useful for local development, as it removes the need to copy all
resources to the lcoal machine along with a database dump.

## Installation

The Flownative Resource Proxy is installed as a regular Flow package via Composer.
For your existing project, simply include `flownative/flow-resourceproxy` into
the dependencies of your Flow or Neos distribution:

    composer require flownative/flow-resourceproxy

## Configuration

To import, the package needs to know the base URI of the remote source and
whether or not it uses subdivided hash path segments.

```yaml
Flownative:
  Flow:
    ResourceProxy:
      storages:
        # the default storage for a Flow setup
        'defaultPersistentResourcesStorage':
          # the remote base URI to the published resources
          remoteSourceBaseUri: 'https://www.acme.com/_Resources/Persistent'
          # whether or not the remote source uses the target setting of the same name
          subdivideHashPathSegment: true
```

After setting up the configuration, clear the Fusion cache to make sure the URIs
will be fetched again and can be checked for needing an import. Similarly,
clearing the existing thumbnails will force the system to re-generate them.

## Trubleshooting

If things don't work as expected, check the system log, the package is pretty
talkative in the debug log level.

## Implementation

In Flow, resource access is handled through the resource management API. These
mehods would need to check for a missing resource and try to fetch it if not
available locally:

- `StorageInterface.getStreamByResource`
- `StorageInterface.getStreamByResourcePath` (unused in a plain Neos setup)
- `TargetInterface.getPublicPersistentResourceUri`

The following are covered by the above methods:

- `Collection.getStreamByResource` via `StorageInterface.getStreamByResource`
- `ResourceManager.getStreamByResource` via `Collection.getStreamByResource`
- `ResourceManager.getPublicPersistentResourceUri` via `TargetInterface.getPublicPersistentResourceUri`
- `ResourceManager.getPublicPersistentResourceUriByHash` via `TargetInterface.getPublicPersistentResourceUri`

On top of that the system uses image variants and thumbnails, assuming those
exist if their metadata can be found in the database. Still, the URI for those
is fetched using the `TargetInterface.getPublicPersistentResourceUri` method.

This package thus advises the `StorageInterface` and `TargetInterface` to
check for missing resources and tries to "import" them as needed.

Note: So far this  only works for the `WritableFileSystemStorage`.
