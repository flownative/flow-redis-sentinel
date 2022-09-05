[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/redis-sentinel.svg)](https://packagist.org/packages/flownative/redis-sentinel)
[![Maintenance level: Love](https://img.shields.io/badge/maintenance-%E2%99%A1%E2%99%A1%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# Flow Redis Cache Backend with Sentinel Support

This package provides a Redis cache backend with
[Sentinel](https://redis.io/topics/sentinel) support.

## Installation

The package is installed as a regular Flow package via Composer. For your
existing project, simply include `flownative/redis-sentinel` into the
dependencies of your Flow or Neos distribution:

```bash
    $ composer require flownative/redis-sentinel:0.*
```

## Usage

The `RedisBackend` contained in this package can be used as a
drop-in-replacement for the Redis backend provided by the neos/cache package.

For regular use with a standalone Redis server, provide configuration in your
`Caches.yaml` like so:

```yaml
Flow_Mvc_Routing_Route:
    backend: 'Flownative\RedisSentinel\RedisBackend'
    backendOptions: &redisBackendOptions
        hostname: '%env:REDIS_HOST%'
        password: '%env:REDIS_PASSWORD%'
        port: '%env:REDIS_PORT%'
        database: 0

Flow_Mvc_Routing_Resolve:
    backend: 'Flownative\RedisSentinel\RedisBackend'
    backendOptions: *redisBackendOptions
    …
```

Of course you can also set concrete values instead of using environment
variables.

When Redis is running in a high availability setup with Sentinel servers, you
need to configure the Redis Backend to access the Sentinel servers instead of
the actual Redis nodes.

Depending on your setup, this may look like the following:

```yaml
Flow_Mvc_Routing_Route:
    backend: 'Flownative\RedisSentinel\RedisBackend'
    backendOptions: &backendOptions
        sentinels:
            - 'tcp://10.101.213.145:26379'
            - 'tcp://10.101.213.146:26379'
            - 'tcp://10.101.213.147:26379'
        service: 'mymaster'
        password: 'a-very-long-password'
        database: 0

Flow_Mvc_Routing_Resolve:
    backend: 'Flownative\RedisSentinel\RedisBackend'
    backendOptions: *backendOptions
    …
``` 

Note that "service" is the name of your Redis cluster (which is "mymaster" in
most default configurations).

## Logging

This cache backend will log errors, such as connection timeouts or other
problems while communicating with the Redis servers.

If a connection error occurs during a request, it is likely, that more errors of
the same type will happen. Therfore, those messages will, by default, be
de-duplicated: If the messages of an error is identical with one which already
has been logged during the current CLI / web request, it will not be logged
another time.

You can disable de-duplication logged errors for debugging purposes by
setting the respective backend option to false:

```yaml
Flow_Mvc_Routing_Route:
    backend: 'Flownative\RedisSentinel\RedisBackend'
    backendOptions:
        database: 0
        …
        deduplicateErrors: false
```

## Tests

You can adjust the host and port used in the functional tests using the
environment variables `REDIS_HOST` and `REDIS_PORT`;

## Credits

This cache backend was developed by Robert Lemke of Flownative, based on the
Neos Flow Redis Backend, originally created by Christopher Hlubek and later
improved by the Neos core team. 
