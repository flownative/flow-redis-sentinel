<?php
declare(strict_types=1);

namespace Flownative\RedisSentinel\Command;

/*
 * This file is part of the Flownative.RedisSentinel package.
 *
 * Copyright (c) 2019 Robert Lemke, Flownative GmbH
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Cache\Exception\NoSuchCacheException;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Cli\CommandController;

class RedisCommandController extends CommandController
{

    /**
     * @Inject()
     * @var CacheManager
     */
    protected $cacheManager;

    /**
     * Test Redis connection
     *
     * @param int $time
     * @return void
     * @throws NoSuchCacheException
     */
    public function testCommand(int $time = 600): void
    {
        $frontend = $this->cacheManager->getCache('Flownative_RedisSentinel_Test');

        $timestamp = time();
        $this->outputLine('Setting cache entry "redis_test" to value "%s"', [$timestamp]);
        $frontend->set('redis_test', $timestamp);

        $frontend->set('redis_tagged_test', 'this is a tagged entry', ['the_tag']);

        $this->outputLine(var_export($frontend->getByTag('the_tag'), true));

        $this->outputLine('Retrieving the cache entry for %s seconds:',[$time]);
        $endTimestamp = $timestamp + $time;
        while (time() < $endTimestamp) {
            $actualTimestamp = $frontend->get('redis_test');
            $this->output->output(($actualTimestamp === $timestamp) ? '<success>*</success>' : '<error>X</error>');
            usleep(100000);
        }
    }
}
