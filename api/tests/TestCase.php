<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This box serves production from the same checkout. A cached config
        // (php artisan config:cache) overrides .env.testing entirely — tests
        // would silently run against the production database, Redis, and SMS
        // provider. Refuse loudly instead. Run `php artisan config:clear`
        // before any test run; re-cache as the last step of a deploy.
        if ($this->app->configurationIsCached()) {
            self::fail(
                'Configuration is cached (bootstrap/cache/config.php). '
                .'Tests would load PRODUCTION config. Run `php artisan config:clear` first.'
            );
        }
    }
}
