<?php

declare(strict_types=1);

namespace Marque\Threepio\Tests;

use Marque\Threepio\ThreepioServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ThreepioServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('threepio.redis.connection', 'default');
        $app['config']->set('threepio.redis.prefix', 'threepio_test:');
        $app['config']->set('threepio.announce_interval', 1800);
        $app['config']->set('threepio.min_announce_interval', 300);
        $app['config']->set('threepio.peer_expiry', 3600);
    }
}
