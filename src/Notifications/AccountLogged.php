<?php

namespace Beliven\Lockout\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountLogged extends Notification
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
     *
     * The message content is translation-ready so package users can
     * customize the subject and lines via translation files.
     *
     * Expected translation keys (example):
     *  - lockout.notifications.account_logged.subject
     *  - lockout.notifications.account_logged.line1
     *  - lockout.notifications.account_logged.line2
     *  - lockout.notifications.account_logged.action
     *  - lockout.notifications.account_logged.footer
     *
     * Each key may use the placeholders:
     *  - :identifier  => the locked identifier (e.g. email)
     *  - :minutes     => lockout duration in minutes
     */
    public function toMail(object $notifiable): MailMessage
    {

        return (new MailMessage)
            ->subject(trans('lockout::lockout.notifications.account_logged.subject'))
            ->line(trans('lockout::lockout.notifications.account_logged.line1', ['identifier' => $this->identifier]))
            ->line(trans('lockout::lockout.notifications.account_logged.line2', ['minutes' => $this->lockoutDuration]))
            ->action(trans('lockout::lockout.notifications.account_logged.action'), $this->signedUnlockUrl)
            ->line(trans('lockout::lockout.notifications.account_logged.footer'));
    }
}
