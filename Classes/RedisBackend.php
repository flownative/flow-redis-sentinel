<?php
declare(strict_types=1);

namespace Flownative\RedisSentinel;

/*
 * This file is part of the Flownative.RedisSentinel package.
 *
 * Copyright (c) 2019 Robert Lemke, Flownative GmbH
 * Copyright (c) 2015 Neos project contributors
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Cache\Backend\AbstractBackend as IndependentAbstractBackend;
use Neos\Cache\Backend\FreezableBackendInterface;
use Neos\Cache\Backend\IterableBackendInterface;
use Neos\Cache\Backend\PhpCapableBackendInterface;
use Neos\Cache\Backend\RequireOnceFromValueTrait;
use Neos\Cache\Backend\TaggableBackendInterface;
use Neos\Cache\Backend\WithStatusInterface;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Error\Messages\Result;
use Predis;
use RuntimeException;

/**
 * Class RedisBackend
 */
class RedisBackend extends IndependentAbstractBackend implements TaggableBackendInterface, IterableBackendInterface, FreezableBackendInterface, PhpCapableBackendInterface, WithStatusInterface
{
    use RequireOnceFromValueTrait;

    public const MIN_REDIS_VERSION = '2.6.0';

    /**
     * @var Predis\Client
     */
    protected $client;

    /**
     * @var integer Cursor used for iterating over cache entries
     */
    protected $entryCursor = 0;

    /**
     * @var boolean|null
     */
    protected $frozen;

    /**
     * @var string
     */
    protected $hostname = '127.0.0.1';

    /**
     * @var integer
     */
    protected $port = 6379;

    /**
     * @var array
     */
    protected $sentinels = [];

    /**
     * @var string
     */
    protected $service = 'mymaster';

    /**
     * @var integer
     */
    protected $database = 0;

    /**
     * @var string
     */
    protected $password = '';

    /**
     * @var integer
     */
    protected $compressionLevel = 0;

    /**
     * Constructs this backend
     *
     * @param EnvironmentConfiguration $environmentConfiguration
     * @param array $options Configuration options - depends on the actual backend
     */
    public function __construct(EnvironmentConfiguration $environmentConfiguration, array $options)
    {
        parent::__construct($environmentConfiguration, $options);
        $this->client = $this->getRedisClient();
    }

    /**
     * Saves data in the cache.
     *
     * @param string $entryIdentifier An identifier for this specific cache entry
     * @param string $data The data to be stored
     * @param array $tags Tags to associate with this cache entry. If the backend does not support tags, this option can be ignored.
     * @param integer $lifetime Lifetime of this cache entry in seconds. If NULL is specified, the default lifetime is used. "0" means unlimited lifetime.
     * @return void
     * @throws RuntimeException
     * @api
     */
    public function set(string $entryIdentifier, string $data, array $tags = [], int $lifetime = null): void
    {
        if ($this->isFrozen()) {
            throw new RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1574776976);
        }

        $this->client->multi();
        $lifetime = $lifetime ?? $this->defaultLifetime;
        if ($lifetime >0) {
            $status = $this->client->set($this->getPrefixedIdentifier('entry:' . $entryIdentifier), $this->compress($data), 'ex', $lifetime);
        } else {
            $status = $this->client->set($this->getPrefixedIdentifier('entry:' . $entryIdentifier), $this->compress($data));
        }

        $this->client->lRem($this->getPrefixedIdentifier('entries'), 0, $entryIdentifier);
        $this->client->rPush($this->getPrefixedIdentifier('entries'), [$entryIdentifier]);

        foreach ($tags as $tag) {
            $this->client->sAdd($this->getPrefixedIdentifier('tag:' . $tag), [$entryIdentifier]);
            $this->client->sAdd($this->getPrefixedIdentifier('tags:' . $entryIdentifier), [$tag]);
        }
        $this->client->exec();
    }

    /**
     * Loads data from the cache.
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     * @return mixed The cache entry's content as a string or false if the cache entry could not be loaded
     * @api
     */
    public function get(string $entryIdentifier)
    {
        return $this->decompress($this->client->get($this->getPrefixedIdentifier('entry:' . $entryIdentifier)));
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier An identifier specifying the cache entry
     * @return boolean true if such an entry exists, false if not
     * @api
     */
    public function has(string $entryIdentifier): bool
    {
        return (bool)$this->client->exists($this->getPrefixedIdentifier('entry:' . $entryIdentifier));
    }

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry but if - for what reason ever -
     * old entries for the identifier still exist, they are removed as well.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     * @return boolean true if (at least) an entry could be removed or false if no entry was found
     * @throws RuntimeException
     * @api
     */
    public function remove(string $entryIdentifier): bool
    {
        if ($this->isFrozen()) {
            throw new RuntimeException(sprintf('Cannot remove cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1323344192);
        }
        do {
            $tagsKey = $this->getPrefixedIdentifier('tags:' . $entryIdentifier);
            $this->client->watch($tagsKey);
            $tags = $this->client->sMembers($tagsKey);
            $this->client->multi();
            $this->client->del([$this->getPrefixedIdentifier('entry:' . $entryIdentifier)]);
            foreach ($tags as $tag) {
                $this->client->sRem($this->getPrefixedIdentifier('tag:' . $tag), $entryIdentifier);
            }
            $this->client->del([$this->getPrefixedIdentifier('tags:' . $entryIdentifier)]);
            $this->client->lRem($this->getPrefixedIdentifier('entries'), 0, $entryIdentifier);
            /** @var array|bool $result */
            $result = $this->client->exec();
        } while ($result === false);

        return true;
    }

    /**
     * Removes all cache entries of this cache
     *
     * The flush method will use the EVAL command to flush all entries and tags for this cache
     * in an atomic way.
     *
     * @return void
     * @throws RuntimeException
     * @api
     */
    public function flush(): void
    {
        $script = "
        local entries = redis.call('LRANGE',KEYS[1],0,-1)
        for k1,entryIdentifier in ipairs(entries) do
            redis.call('DEL', ARGV[1]..'entry:'..entryIdentifier)
            local tags = redis.call('SMEMBERS', ARGV[1]..'tags:'..entryIdentifier)
            for k2,tagName in ipairs(tags) do
                redis.call('DEL', ARGV[1]..'tag:'..tagName)
            end
            redis.call('DEL', ARGV[1]..'tags:'..entryIdentifier)
        end
        redis.call('DEL', KEYS[1])
        redis.call('DEL', KEYS[2])
        ";
        $this->client->eval($script, 2, $this->getPrefixedIdentifier('entries'), $this->getPrefixedIdentifier('frozen'), $this->getPrefixedIdentifier(''));
        $this->frozen = null;
    }

    /**
     * This backend does not need an externally triggered garbage collection
     *
     * @return void
     * @api
     */
    public function collectGarbage(): void
    {
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     * @return integer The number of entries which have been affected by this flush
     * @throws RuntimeException
     * @api
     */
    public function flushByTag(string $tag): int
    {
        if ($this->isFrozen()) {
            throw new RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1574777747);
        }

        $script = "
        local entries = redis.call('SMEMBERS', KEYS[1])
        for k1,entryIdentifier in ipairs(entries) do
            redis.call('DEL', ARGV[1]..'entry:'..entryIdentifier)
            local tags = redis.call('SMEMBERS', ARGV[1]..'tags:'..entryIdentifier)
            for k2,tagName in ipairs(tags) do
                redis.call('SREM', ARGV[1]..'tag:'..tagName, entryIdentifier)
            end
            redis.call('DEL', ARGV[1]..'tags:'..entryIdentifier)
            redis.call('LREM', KEYS[2], 0, entryIdentifier)
        end
        return #entries
        ";
        return $this->client->eval($script, 2, $this->getPrefixedIdentifier('tag:' . $tag), $this->getPrefixedIdentifier('entries'), $this->getPrefixedIdentifier(''));
    }

    /**
     * Finds and returns all cache entry identifiers which are tagged by the
     * specified tag.
     *
     * @param string $tag The tag to search for
     * @return string[] An array with identifiers of all matching entries. An empty array if no entries matched
     * @api
     */
    public function findIdentifiersByTag(string $tag): array
    {
        return $this->client->sMembers($this->getPrefixedIdentifier('tag:' . $tag));
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        return $this->get($this->key());
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        $this->entryCursor++;
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        $entryIdentifier = $this->client->lIndex($this->buildKey('entries'), $this->entryCursor);

        if ($entryIdentifier === null || !$this->has($entryIdentifier)) {
            return false;
        }
        return $entryIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    public function valid(): bool
    {
        return $this->key() !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        $this->entryCursor = 0;
    }

    /**
     * Freezes this cache backend.
     *
     * All data in a frozen backend remains unchanged and methods which try to add
     * or modify data result in an exception thrown. Possible expiry times of
     * individual cache entries are ignored.
     *
     * A frozen backend can only be thawn by calling the flush() method.
     *
     * @return void
     * @throws RuntimeException
     */
    public function freeze(): void
    {
        if ($this->isFrozen()) {
            throw new RuntimeException(sprintf('Cannot add or modify cache entry because the backend of cache "%s" is frozen.', $this->cacheIdentifier), 1574777766);
        }
        do {
            $entriesKey = $this->getPrefixedIdentifier('entries');
            $this->client->watch($entriesKey);
            $entries = $this->client->lRange($entriesKey, 0, -1);
            $this->client->multi();
            foreach ($entries as $entryIdentifier) {
                $this->client->persist($this->getPrefixedIdentifier('entry:' . $entryIdentifier));
            }
            $this->client->set($this->getPrefixedIdentifier('frozen'), '1');
            /** @var array|bool $result */
            $result = $this->client->exec();
        } while ($result === false);
        $this->frozen = true;
    }

    /**
     * Tells if this backend is frozen.
     *
     * @return boolean
     */
    public function isFrozen(): bool
    {
        if (null === $this->frozen) {
            $this->frozen = (bool)$this->client->exists($this->getPrefixedIdentifier('frozen'));
        }
        return $this->frozen;
    }

    /**
     * Sets the hostname or the socket of the Redis server
     *
     * @param string $hostname Hostname of the Redis server
     * @api
     */
    public function setHostname(string $hostname): void
    {
        $this->hostname = $hostname;
    }

    /**
     * Sets the port of the Redis server.
     *
     * Unused if you want to connect to a socket (i.e. hostname contains a /)
     *
     * @param integer|string $port Port of the Redis server
     * @api
     */
    public function setPort($port): void
    {
        $this->port = (int)$port;
    }

    /**
     * Set the connection addresses for Redis Sentinel servers.
     *
     * If at least one Sentinel server is specified, this client operates in Sentinel mode
     * and ignores "hostname" and "port".
     *
     * @param array|string $sentinels Sentinel server addresses, eg. ['tcp://10.101.213.145:26379', 'tcp://…'], or string with comma separated addresses
     */
    public function setSentinels($sentinels): void
    {
        if (is_string($sentinels)) {
            $this->sentinels = explode(',', $sentinels);
        } elseif (is_array($sentinels)) {
            $this->sentinels = $sentinels;
        } else {
            throw new \InvalidArgumentException(sprintf('setSentinels(): Invalid type %s, string or array expected', gettype($sentinels)), 1575384806);
        }
    }

    /**
     * @param string $service
     */
    public function setService(string $service): void
    {
        $this->service = $service;
    }

    /**
     * Sets the database that will be used for this backend
     *
     * @param integer|string $database Database that will be used
     * @api
     */
    public function setDatabase($database): void
    {
        $this->database = (int)$database;
    }

    /**
     * @param string $password
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * @param integer|string $compressionLevel
     */
    public function setCompressionLevel($compressionLevel): void
    {
        $this->compressionLevel = (int)$compressionLevel;
    }

    /**
     * @param Predis\Client $client
     * @return void
     */
    public function setClient(Predis\Client $client = null): void
    {
        if ($client !== null) {
            $this->client = $client;
        }
    }

    /**
     * @param string|bool $value
     * @return string|bool
     */
    private function decompress($value)
    {
        if (empty($value)) {
            return false;
        }
        return $this->useCompression() ? gzdecode((string)$value) : $value;
    }

    /**
     * @param string $value
     * @return string
     */
    private function compress(string $value): string
    {
        return $this->useCompression() ? gzencode($value, $this->compressionLevel) : $value;
    }

    /**
     * @return boolean
     */
    private function useCompression(): bool
    {
        return $this->compressionLevel > 0;
    }

    /**
     * Validates that the configured redis backend is accessible and returns some details about its configuration if that's the case
     *
     * FIXME: Implement
     *
     * @return Result
     */
    public function getStatus(): Result
    {
        return new Result();
    }

    /**
     * @return Predis\Client
     */
    private function getRedisClient(): \Predis\Client
    {
        $options = [
            'parameters' => [
                'database' => $this->database
            ]
        ];

        if (!empty($this->password)) {
            $options['parameters']['password'] = $this->password;
        }

        if ($this->sentinels !== []) {
            $connectionParameters = $this->sentinels;
            $options['replication'] = 'sentinel';
            $options['service'] = $this->service;
        } else {
            $connectionParameters = 'tcp://' . $this->hostname . ':' . $this->port;
        }
        return new Predis\Client($connectionParameters, $options);
    }
}
