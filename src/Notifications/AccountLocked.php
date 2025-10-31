<?php

namespace Beliven\Lockout\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountLocked extends Notification
{
    use Queueable;

    protected string $identifier;

    protected int $lockoutDuration; // in minutes

    protected string $signedUnlockUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $identifier, int $lockoutDuration, string $signedUnlockUrl)
    {
        $this->identifier = $identifier;
        $this->lockoutDuration = $lockoutDuration;
        $this->signedUnlockUrl = $signedUnlockUrl;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('lockout.notification_channels');
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Account Locked Due to Multiple Failed Login Attempts')
            ->line("Your account with identifier '{$this->identifier}' has been locked due to multiple failed login attempts.")
            ->line("The lockout will last for {$this->lockoutDuration} minutes.")
            ->action('Reset Your Password', url('/account/unlock'))
            ->line('If you did not attempt to log in, please contact support immediately.');
    }
}
