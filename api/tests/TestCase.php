<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        /*
         * BEFORE parent::setUp(), and that ordering is the whole point.
         *
         * RefreshDatabase does its work from inside parent::setUp() —
         * setUpTraits() runs the trait's hook, which migrates the database
         * fresh. A guard placed after the parent call therefore reports the
         * danger it was supposed to prevent, having already caused it. That
         * is not theoretical: this check was written after it, run once with
         * DB_DATABASE pointed at production to prove it worked, and destroyed
         * the live database in the process of proving it.
         *
         * The name is the check because it is the thing that differs:
         * phpunit.xml pins DB_DATABASE to manfaa_test, and any run that has
         * lost that — a stale cached config, an exported DB_DATABASE, an
         * edited phpunit.xml — lands on a name without "test" in it.
         */
        // Read straight from the environment, not through config(): the
        // container is not booted until parent::setUp(), and config() before
        // it throws. phpunit.xml sets DB_DATABASE, which is precisely the
        // thing that goes missing when a run is dangerous.
        $database = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: '');

        if (! str_contains($database, 'test')) {
            self::fail(sprintf(
                'Refusing to run: DB_DATABASE is [%s], which is not a test '
                .'database. RefreshDatabase would DROP EVERY TABLE in it. '
                .'phpunit.xml must set DB_DATABASE to the test database; check '
                .'it, and any DB_DATABASE exported in your shell.',
                $database === '' ? 'unset' : $database,
            ));
        }

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

        /*
         * No test may reach the network.
         *
         * The transfer profiles tests build carry the REAL bank gateway
         * (http://10.99.0.1:3005), because that is what the code under test
         * reads. A targeted fake — Http::fake(['*\/faisanet/history*' => …])
         * — covers the one URL it names and lets every other call through to
         * that gateway for real. That is how it was found: the suite sat in
         * SYN-SENT against the bank, ten seconds a call, because the tunnel
         * happened to be down. With the tunnel up it would have been talking
         * to the bank instead.
         *
         * preventStrayRequests turns any un-faked call into an immediate
         * failure naming the URL. A hang becomes a sentence.
         */
        Http::preventStrayRequests();
    }
}
