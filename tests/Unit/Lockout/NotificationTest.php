<?php

use Beliven\Lockout\Events\EntityLocked;
use Beliven\Lockout\Listeners\OnEntityLocked;
use Beliven\Lockout\Notifications\AccountLocked;
use Beliven\Lockout\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

describe('Lockout notifications', function () {
    beforeEach(function () {
        // Ensure notification sending is enabled for the Lockout service
        config()->set('lockout.unlock_via_notification', true);
        // Use the test fixture user model
        config()->set('auth.providers.users.model', \Beliven\Lockout\Tests\Fixtures\User::class);

        // Create the users table used by the fixture
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();
        });

        // Ensure no notifications are sent before each test
        Notification::fake();
    });

    afterEach(function () {
        Schema::dropIfExists('users');
    });

    it('sends AccountLocked notification when identifier is a valid email and model is notifiable', function () {
        $email = 'notify@example.test';

        // Seed a notifiable user record
        User::query()->create([
            'email'    => $email,
            'password' => 'secret',
        ]);

        // Dispatch the listener directly (it delegates to the Lockout service)
        $listener = new OnEntityLocked;
        $listener->handle(new EntityLocked($email, (object) ['ip' => '127.0.0.1', 'user_agent' => 'phpunit']));

        // Assert notification was sent to the created user
        $user = User::query()->where('email', $email)->first();
        expect($user)->not->toBeNull();

        Notification::assertSentTo(
            [$user],
            AccountLocked::class,
            function ($notification, $channels) {
                // Basic sanity: notification instance is AccountLocked and channels is array
                return $notification instanceof AccountLocked && is_array($channels);
            }
        );
    });

    it('does not send notification when identifier is not a valid email', function () {
        $badIdentifier = 'not-an-email';

        // Even if a model exists with that value in some field, the Lockout service
        // validates the identifier as an email before attempting notification.
        User::query()->create([
            'email'    => 'other@example.test',
            'password' => 'secret',
        ]);

        $listener = new OnEntityLocked;
        $listener->handle(new EntityLocked($badIdentifier, (object) ['ip' => '127.0.0.1']));

        // No notifications should have been sent
        Notification::assertNothingSent();
    });

    it('attemptSendLockoutNotification is a no-op when unlock_via_notification is disabled', function () {
        // Create a notifiable user
        $email = 'disabled@example.test';
        User::query()->create([
            'email'    => $email,
            'password' => 'secret',
        ]);

        // Disable the feature and call the listener
        config()->set('lockout.unlock_via_notification', false);

        $listener = new OnEntityLocked;
        $listener->handle(new EntityLocked($email, (object) ['ip' => '127.0.0.1']));

        Notification::assertNothingSent();
    });
});
