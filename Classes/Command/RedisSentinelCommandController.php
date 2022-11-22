<?php
declare(strict_types=1);

namespace Flownative\RedisSentinel\Command;

/*
 * This file is part of the Flownative.RedisSentinel package.
 *
 * Copyright (c) Robert Lemke, Flownative GmbH
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\RedisSentinel\RedisBackend;
use Neos\Cache\Backend\IterableMultiBackend;
use Neos\Cache\Backend\MultiBackend;
use Neos\Cache\Backend\TaggableMultiBackend;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Exception\NoSuchCacheException;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Cli\CommandController;
use Predis\Client;
use Predis\Command\Redis\INFO;
use Predis\Connection\Replication\SentinelReplication;

#[Flow\Scope("singleton")]
class RedisSentinelCommandController extends CommandController
{
    #[Flow\Inject]
    protected CacheManager $cacheManager;

    /**
     * List Redis Sentinel caches
     *
     * This command displays configuration of Redis Sentinel backends, even when they are part of a Multi Backend.
     */
    public function listCommand(): void
    {
        $cacheConfigurations = $this->cacheManager->getCacheConfigurations();
        $redisSentinelBackends = [];
        foreach ($cacheConfigurations as $cacheIdentifier => $cacheConfiguration) {
            if (isset($cacheConfiguration['backend'])) {
                if ($cacheConfiguration['backend'] === RedisBackend::class) {
                    $redisSentinelBackends[$cacheIdentifier] = $cacheConfiguration;
                    $redisSentinelBackends[$cacheIdentifier]['multiCache'] = false;
                } elseif ($cacheConfiguration['backend'] === MultiBackend::class || $cacheConfiguration['backend'] === TaggableMultiBackend::class || $cacheConfiguration['backend'] === IterableMultiBackend::class) {
                    foreach ($cacheConfiguration['backendOptions']['backendConfigurations'] as $subCacheConfiguration) {
                        if ($subCacheConfiguration['backend'] === RedisBackend::class) {
                            $redisSentinelBackends[$cacheIdentifier] = $subCacheConfiguration;
                            $redisSentinelBackends[$cacheIdentifier]['multiCache'] = true;
                        }
                    }
                }
            }
        }
        $rows = [];
        foreach ($redisSentinelBackends as $cacheIdentifier => $cacheConfiguration) {
            $host = $cacheConfiguration['backendOptions']['hostname'] ?? '';
            if (isset($cacheConfiguration['backendOptions']['sentinels'])) {
                $host = implode(', ', $cacheConfiguration['backendOptions']['sentinels']);
            }

            $rows[] = [
                $cacheConfiguration['multiCache'] ? 'yes' : 'no',
                $cacheIdentifier,
                $host,
                $cacheConfiguration['backendOptions']['port'] ?? '',
                (!empty($cacheConfiguration['backendOptions']['password']) ? 'yes' : 'no'),
            ];
        }
        $this->output->outputTable($rows, [
            'Multi',
            'Cache Identifier',
            'Host / Sentinels',
            'Port',
            'Password'
        ]);
    }

    /**
     * Check Redis connection
     *
     */
    public function connectCommand(string $cacheIdentifier): void
    {
        $cacheConfigurations = $this->cacheManager->getCacheConfigurations();

        $this->output('Looking up cache ');

        try {
            $cache = $this->cacheManager->getCache($cacheIdentifier);
            $this->outputLine('<success>✔</success>');
        } catch (NoSuchCacheException $e) {
            $this->outputLine('<error>X</error>');
            $this->outputLine('<error>The specified cache does not exist.</error>');
            exit(1);
        }

        $cacheBackend = $cache->getBackend();
        $backendConfiguration = $cacheConfigurations[$cacheIdentifier]['backendOptions'];

        if ($cacheBackend instanceof MultiBackend) {
            $this->output('Multi Backend detected, looking up actual cache ');

            if (!isset($cacheConfigurations[$cacheIdentifier]['backendOptions']['backendConfigurations'])) {
                $this->outputLine('<error>X</error>');
                $this->outputLine('<error>Configuration of %s has an unexpected structure.</error>', [$cacheIdentifier]);
                exit(1);
            }

            $cacheBackend = null;
            foreach ($cacheConfigurations[$cacheIdentifier]['backendOptions']['backendConfigurations'] as $backendConfiguration) {
                if ($backendConfiguration['backend'] === RedisBackend::class) {
                    $cacheBackend = new RedisBackend(
                        new EnvironmentConfiguration('Redis Sentinel Connectivity Test', FLOW_PATH_DATA, PHP_MAXPATHLEN),
                        $backendConfiguration['backendOptions']
                    );
                    $cache = new StringFrontend($cacheIdentifier, $cacheBackend);
                    break;
                }
            }

            if ($cacheBackend === null) {
                $this->outputLine('<error>X</error>');
                $this->outputLine('<error>No Redis Sentinel Backend found in configuration of cache %s.</error>', [$cacheIdentifier]);
                exit(1);
            }

            $this->outputLine('<success>✔</success>');
        }

        $this->output('Initializing client ');
        try {
            $client = $this->getRedisClient(
                $backendConfiguration['backendOptions']['sentinels'] ?? [],
                $backendConfiguration['backendOptions']['password'] ?? '',
                $backendConfiguration['backendOptions']['service'] ?? '',
                $backendConfiguration['backendOptions']['hostname'] ?? '',
                $backendConfiguration['backendOptions']['port'] ?? 6379,
                $backendConfiguration['backendOptions']['database'] ?? 1,
                $backendConfiguration['backendOptions']['timeout'] ?? 10,
                $backendConfiguration['backendOptions']['readWriteTimeout'] ?? 10,
            );
            $this->outputLine('<success>✔</success>');

        } catch (\Throwable $throwable) {
            $this->outputLine('<error>X</error>');
            $this->outputLine('<error>%s</error>', [$throwable->getMessage()]);
            exit(1);
        }

        $clientConnection = $client->getConnection();
        $usesSentinel = false;

        if ($clientConnection instanceof SentinelReplication) {
            $this->output('Opening Sentinel connection ');
            $usesSentinel = true;
            try {
                $sentinelConnection = $clientConnection->getSentinelConnection();
                $sentinelConnection->connect();
                $result = $sentinelConnection->executeCommand(new INFO());
                if ($result instanceof \Predis\Response\Error) {
                    throw new \Error($result->getMessage());
                }
                $this->outputLine('<success>✔</success>');

                if (preg_match('/redis_version:([0-9.]+)/', $result, $matches) === 1) {
                    $this->outputLine('Sentinel server identified with version ' . $matches[1]);
                }
            } catch (\Throwable $throwable) {
                $this->outputLine('<error>X</error>');
                $this->outputLine('<error>%s</error>', [$throwable->getMessage()]);

                if (str_contains($throwable->getMessage(), 'NOAUTH')) {
                    $usesPassword = empty(!isset($sentinelConnection) || $sentinelConnection->getParameters()->password);
                    if ($usesPassword) {
                        $this->outputLine('Note: There was <u>no Sentinel password</u> defined in the backend options of this cache backend');
                        $this->outputLine();
                    } else {
                        $this->outputLine('The connection failed even though there was a password defined in the backend options');
                    }
                }
                exit(1);
            }
        }

        $this->output('Opening connection using %s ', [$usesSentinel ? 'Redis Sentinel' : 'a direct Redis connection']);
        try {
            $client->connect();
            $this->outputLine('<success>✔</success>');
        } catch (\Throwable $throwable) {
            $this->outputLine('<error>X</error>');
            $this->outputLine('<error>%s</error>', [$throwable->getMessage()]);

            if (str_contains($throwable->getMessage(), 'NOAUTH')) {
                $usesPassword = (empty($backendConfiguration['backendOptions']['password']));
                if ($usesPassword) {
                    $this->outputLine('Note: There was <u>no password</u> defined in the backend options of this cache backend');
                    $this->outputLine();
                } else {
                    $this->outputLine('The connection failed even though there was a password defined in the backend options');
                }
            }

            /** @noinspection ForgottenDebugOutputInspection */
            var_export($backendConfiguration['backendOptions']);
            $this->outputLine();
            exit(1);
        }

        $expectedContent = (string)microtime();
        $entryIdentifier = 'redis-sentinel-connectivity-test';
        $entryTag = 'redis-sentinel-connectivity-test-tag';

        $this->output('Setting cache entry ');

        try {
            $cache->set($entryIdentifier, $expectedContent, [$entryTag]);
            $this->outputLine('<success>✔</success>');
        } catch (\Throwable $throwable) {
            $this->outputLine('<error>X</error>');
            $this->outputLine('<error>%s</error>', [$throwable->getMessage()]);
            exit(1);
        }

        $this->output('Retrieving cache entry by identifier ');

        try {
            $actualContent = $cache->get($entryIdentifier);
            if ($actualContent !== $expectedContent) {
                throw new \Error(sprintf('Returned content "%s" does not match expected content "%s"', $actualContent, $expectedContent), 1669124609);
            }
            $this->outputLine('<success>✔</success>');
        } catch (\Throwable $throwable) {
            $this->outputLine('<error>X</error>');
            $this->outputLine('<error>%s</error>', [$throwable->getMessage()]);
            exit(1);
        }

        $this->output('Retrieving cache entry by tag ');

        try {
            $results = $cache->getByTag($entryTag);
            if (count($results) !== 1) {
                throw new \Error(sprintf('Returned %s results instead of 1"', count($results)), 1669133519);
            }
            $actualContent = current($results);
            if ($actualContent !== $expectedContent) {
                throw new \Error(sprintf('Returned content "%s" does not match expected content "%s"', $actualContent, $expectedContent), 1669133552);
            }
            $this->outputLine('<success>✔</success>');
        } catch (\Throwable $throwable) {
            $this->outputLine('<error>X</error>');
            $this->outputLine('<error>%s</error>', [$throwable->getMessage()]);
            exit(1);
        }

        $this->output('Removing cache entry ');

        try {
            $cache->remove($entryIdentifier);
            $actualContent = $cache->get($entryIdentifier);
            if ($actualContent !== false) {
                throw new \Error('Cache entry was not removed, it is still there', 1669133616);
            }
            $this->outputLine('<success>✔</success>');
        } catch (\Throwable $throwable) {
            $this->outputLine('<error>X</error>');
            $this->outputLine('<error>%s</error>', [$throwable->getMessage()]);
            exit(1);
        }

        $this->outputLine();
        $this->outputLine('<success>Everything seems to work</success>');
    }

    private function getRedisClient(array $sentinels, string $password, string $service, string $hostname, int $port, int $database, int $timeout, int $readWriteTimeout): Client
    {
        $options = [
            'parameters' => [
                'database' => $database,
                'timeout' => $timeout,
                'read_write_timeout' => $readWriteTimeout,
            ]
        ];

        if (!empty($password)) {
            $options['parameters']['password'] = $password;
        }

        if ($sentinels !== []) {
            $connectionParameters = $sentinels;
            $options['replication'] = 'sentinel';
            $options['service'] = $service;
        } else {
            $connectionParameters = 'tcp://' . $hostname . ':' . $port;
        }
        return new Client($connectionParameters, $options);
    }
}
