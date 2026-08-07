<?php
declare(strict_types=1);
namespace Flownative\RedisSentinel\Tests\Functional;

/*
 * This file is part of the Flownative.RedisSentinel package.
 *
 * Copyright (c) Robert Lemke, Flownative GmbH
 * Copyright (c) Neos project contributors
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\RedisSentinel\RedisBackend;
use Flownative\RedisSentinel\Tests\BaseTestCase;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Frontend\FrontendInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Testcase for the redis cache backend
 *
 * These tests use an actual Redis instance and will place and remove keys in db 0!
 * Since all keys have the 'TestCache:' prefix, running the tests should have
 * no side effects on non-related cache entries.
 *
 * Tests require Redis listening on 127.0.0.1:6379.
 */
class RedisBackendTest extends BaseTestCase
{
    private ?RedisBackend $backend = null;

    private FrontendInterface&MockObject $cache;

    /**
     * Set up test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        $redisHost = getenv('REDIS_HOST') !== false ? getenv('REDIS_HOST') : '127.0.0.1';
        $redisPort = (int)(getenv('REDIS_PORT') !== false ? getenv('REDIS_PORT') : '6379');
        $redisPassword = getenv('REDIS_PASSWORD') !== false ? getenv('REDIS_PASSWORD') : '';

        try {
            if (!@fsockopen($redisHost, $redisPort)) {
                $this->markTestSkipped('Redis server not reachable at ' . $redisHost . ':' . $redisPort);
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis server not reachable at ' . $redisHost . ':' . $redisPort);
        }
        $this->backend = new RedisBackend(
            new EnvironmentConfiguration('Redis a wonderful color Testing', '/some/path', PHP_MAXPATHLEN),
            [
                'hostname' => $redisHost,
                'port' => $redisPort,
                'password' => $redisPassword,
                'database' => 0,
            ]
        );
        $this->cache = $this->createMock(FrontendInterface::class);
        $this->cache->method('getIdentifier')->willReturn('TestCache');
        $this->backend->setCache($this->cache);
        $this->backend->flush();
    }

    /**
     * Tear down test case
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->backend instanceof RedisBackend) {
            $this->backend->flush();
        }
    }

    #[Test]
    public function setAddsCacheEntry(): void
    {
        $this->backend->set('some_entry', 'foo');
        self::assertEquals('foo', $this->backend->get('some_entry'));
    }

    #[Test]
    public function setAddsTags(): void
    {
        $this->backend->set('some_entry', 'foo', ['tag1', 'tag2']);
        $this->backend->set('some_other_entry', 'foo', ['tag2', 'tag3']);

        self::assertEquals(['some_entry'], $this->backend->findIdentifiersByTag('tag1'));
        $expected = ['some_entry', 'some_other_entry'];
        $actual = $this->backend->findIdentifiersByTag('tag2');

        // since Redis does not garantuee the order of values in sets, manually sort the array for comparison
        natsort($actual);
        $actual = array_values($actual);

        self::assertEquals($expected, $actual);
        self::assertEquals(['some_other_entry'], $this->backend->findIdentifiersByTag('tag3'));
    }

    #[Test]
    public function setDoesNotAddMultipleEntries(): void
    {
        $this->backend->set('some_entry', 'foo');
        $this->backend->set('some_entry', 'bar');

        $entryIdentifiers = [];
        foreach ($this->backend as $entryIdentifier => $entryValue) {
            $entryIdentifiers[] = $entryIdentifier;
        }

        self::assertEquals(['some_entry'], $entryIdentifiers);
    }

    #[Test]
    public function cacheIsIterable(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->backend->set('entry_' . $i, 'foo');
        }
        $actualEntries = [];
        foreach ($this->backend as $key => $value) {
            $actualEntries[] = $key;
        }

        self::assertCount(100, $actualEntries);

        for ($i = 0; $i < 100; $i++) {
            self::assertContains('entry_' . $i, $actualEntries);
        }
    }

    #[Test]
    public function freezeFreezesTheCache(): void
    {
        self::assertFalse($this->backend->isFrozen());
        for ($i = 0; $i < 10; $i++) {
            $this->backend->set('entry_' . $i, 'foo');
        }
        $this->backend->freeze();
        self::assertTrue($this->backend->isFrozen());
    }

    #[Test]
    public function flushByTagFlushesEntryByTag(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->backend->set('entry_' . $i, 'foo', ['tag1', 'tag2']);
        }
        for ($i = 10; $i < 20; $i++) {
            $this->backend->set('entry_' . $i, 'foo', ['tag2']);
        }
        self::assertCount(10, $this->backend->findIdentifiersByTag('tag1'));
        self::assertCount(20, $this->backend->findIdentifiersByTag('tag2'));

        $count = $this->backend->flushByTag('tag1');
        self::assertEquals(10, $count, 'flushByTag returns amount of flushed entries');
        self::assertCount(0, $this->backend->findIdentifiersByTag('tag1'));
        self::assertCount(10, $this->backend->findIdentifiersByTag('tag2'));
    }

    #[Test]
    public function flushByTagRemovesEntries(): void
    {
        $this->backend->set('some_entry', 'foo', ['tag1', 'tag2']);

        $this->backend->flushByTag('tag1');

        $entryIdentifiers = [];
        foreach ($this->backend as $entryIdentifier => $entryValue) {
            $entryIdentifiers[] = $entryIdentifier;
        }

        self::assertEquals([], $entryIdentifiers);
    }

    #[Test]
    public function flushByTagsFlushesEntriesOfAllGivenTags(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->backend->set('entry_' . $i, 'foo', ['tag_' . $i]);
        }

        // Force the tags to be flushed in more than one batch:
        $this->inject($this->backend, 'batchSize', 2);

        $count = $this->backend->flushByTags(['tag_0', 'tag_1', 'tag_2', 'tag_3', 'tag_4', 'tag_5']);
        self::assertEquals(6, $count, 'flushByTags returns amount of flushed entries');

        for ($i = 0; $i < 6; $i++) {
            self::assertFalse($this->backend->has('entry_' . $i));
        }
    }

    #[Test]
    public function flushFlushesCache(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->backend->set('entry_' . $i, 'foo', ['tag1']);
        }
        self::assertTrue($this->backend->has('entry_5'));
        $this->backend->flush();
        self::assertFalse($this->backend->has('entry_5'));
    }

    #[Test]
    public function removeRemovesEntryFromCache(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->backend->set('entry_' . $i, 'foo', ['tag1']);
        }
        self::assertCount(10, $this->backend->findIdentifiersByTag('tag1'));
        self::assertEquals('foo', $this->backend->get('entry_1'));
        $actualEntries = [];
        foreach ($this->backend as $key => $value) {
            $actualEntries[] = $key;
        }
        self::assertCount(10, $actualEntries);

        $this->backend->remove('entry_3');
        self::assertCount(9, $this->backend->findIdentifiersByTag('tag1'));
        self::assertFalse($this->backend->get('entry_3'));
        $actualEntries = [];
        foreach ($this->backend as $key => $value) {
            $actualEntries[] = $key;
        }
        self::assertCount(9, $actualEntries);
    }

    #[Test]
    public function expiredEntriesAreSkippedWhenIterating(): void
    {
        $this->backend->set('entry1', 'foo', [], 1);
        sleep(2);
        self::assertFalse($this->backend->has('entry1'));

        $actualEntries = [];
        foreach ($this->backend as $key => $value) {
            $actualEntries[] = $key;
        }
        self::assertEmpty($actualEntries, 'Entries should be empty');
    }
}
