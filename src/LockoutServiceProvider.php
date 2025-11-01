<?php

namespace Beliven\Lockout;

use Beliven\Lockout\Commands\PruneLockouts;
use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Listeners\MarkModelAsLocked;
use Beliven\Lockout\Listeners\OnEntityLocked;
use Beliven\Lockout\Listeners\RecordFailedLoginAttempt;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LockoutServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-lockout')
            ->hasConfigFile()
            ->hasRoute('web')
            ->hasMigration('create_lockout_logs_table')
            ->hasMigration('create_model_lockouts_table')
            ->hasTranslations()
            ->hasCommand(PruneLockouts::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Register listeners for failed authentication attempts and lockout events.
        Event::listen(Failed::class, RecordFailedLoginAttempt::class);

        // When an entity is locked, mark the model as locked and handle notification.
        Event::listen(EntityLocked::class, MarkModelAsLocked::class);
        Event::listen(EntityLocked::class, OnEntityLocked::class);
    }
}
