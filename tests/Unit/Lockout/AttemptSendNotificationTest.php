<?php

use Beliven\Lockout\Lockout;
use Beliven\Lockout\Notifications\AccountLocked;
use Beliven\Lockout\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

describe('Lockout::attemptSendLockoutNotification', function () {
    beforeEach(function () {
        // Enable notification feature by default for these tests
        config()->set('lockout.unlock_via_notification', true);

        // Ensure any global notifications are faked
        Notification::fake();
    });

    afterEach(function () {
        // Clean up users table if created
        try {
            Schema::dropIfExists('users');
        } catch (\Throwable $_) {
            // ignore
        }

        // Reset auth provider override
        config()->set('auth.providers.users.model', null);
    });

    it('is a no-op when no model exists for the given identifier', function () {
        // Use a resolver that returns null from first() to avoid any DB access
        if (!class_exists('ResolvedNullForTest')) {
            eval(<<<'PHP'
            class ResolvedNullForTest
            {
                public static function where($field, $value) {
                    return new class { public function first() { return null; } };
                }
            }
            PHP
            );
        }

        config()->set('auth.providers.users.model', ResolvedNullForTest::class);

        $service = app(Lockout::class);

        // Call with an email that does not correspond to any persisted model
        $service->attemptSendLockoutNotification('missing@example.test', (object) ['ip' => '127.0.0.1']);

        Notification::assertNothingSent();
    });

    it('is a no-op when the resolved model does not implement notify()', function () {
        // Define a resolver class that returns a Model instance without notify()
        if (!class_exists('ResolvedModelNoNotifyForTest')) {
            eval(<<<'PHP'
            class ResolvedModelNoNotifyForTest
            {
                public static function where($field, $value) {
                    return new class {
                        public function first() {
                            return new class extends \Illuminate\Database\Eloquent\Model {
                                // intentionally omit notify() to simulate a non-notifiable model
                                public $timestamps = false;
                            };
                        }
                    };
                }
            }
            PHP
            );
        }

        config()->set('auth.providers.users.model', ResolvedModelNoNotifyForTest::class);

        $service = app(Lockout::class);

        // Use a valid-looking email so the validator passes, but the resolved model lacks notify()
        $service->attemptSendLockoutNotification('nonotify@example.test', (object) ['ip' => '127.0.0.1']);

        Notification::assertNothingSent();
    });

    it('sends AccountLocked notification when identifier is a valid email and model is notifiable', function () {
        $email = 'notify@example.test';

        // Create the users table and seed a User record (the fixture is Notifiable)
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        // Ensure the package resolves the User fixture as the login model
        config()->set('auth.providers.users.model', \Beliven\Lockout\Tests\Fixtures\User::class);

        // Create a notifiable user
        User::query()->create([
            'email'    => $email,
            'password' => 'secret',
        ]);

        $service = app(Lockout::class);

        // Call the method under test
        $service->attemptSendLockoutNotification($email, (object) ['ip' => '127.0.0.1', 'user_agent' => 'phpunit']);

        // Assert the notification was sent to the created user
        $user = User::query()->where('email', $email)->first();
        expect($user)->not->toBeNull();

        Notification::assertSentTo(
            $user,
            AccountLocked::class,
            function ($notification, $channels) use ($user, $email) {
                if (!($notification instanceof AccountLocked) || !is_array($channels)) {
                    return false;
                }

                // Basic sanity: the notification can render a mail message and include expected subject/lines
                $mail = $notification->toMail($user);

                $subjectOk = ($mail->subject ?? null) === 'Account Locked Due to Multiple Failed Login Attempts';

                $introLines = $mail->introLines ?? ($mail->lines ?? []);
                $expectedLine = "Your account with identifier {$email} has been locked due to multiple failed login attempts.";

                return $subjectOk && in_array($expectedLine, $introLines, true);
            }
        );
    });
});
