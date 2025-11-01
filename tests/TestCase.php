<?php

namespace Beliven\Lockout\Tests;

use Beliven\Lockout\LockoutServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Beliven\\Lockout\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LockoutServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Load migration stubs from the package so tests have the required tables.
        // We only include files that end with .php or .stub and call up() on the anonymous class
        // returned by the include to execute the migration. Any errors are swallowed to avoid
        // breaking the test bootstrap.
        foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../database/migrations') as $migration) {
            $path = $migration->getRealPath();
            if (!preg_match('/\.php($|\.stub$)/', $path)) {
                continue;
            }
            try {
                $migrationObj = include $path;
                if (is_object($migrationObj) && method_exists($migrationObj, 'up')) {
                    $migrationObj->up();
                }
            } catch (\Throwable $_) {
                // Swallow exceptions so test setup proceeds even if a migration can't be applied.
            }
        }
    }
}
