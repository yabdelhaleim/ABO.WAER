<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * The password reset email for the admin back office.
 *
 * Laravel's own reset notification points its button at the `password.reset`
 * route — the store front's route. The back office resets on a route of its
 * own, so this notification changes only where the link goes: the token and
 * the address travel as the same two parameters the framework expects, and
 * the host is still resolved from the application URL rather than written
 * into this class.
 *
 * Extending the framework's notification instead of writing one from scratch
 * keeps the standard flow intact — the `mail` channel, the queueable base,
 * the `createUrlUsing` and `toMailUsing` extension points, and the wording
 * of the message all stay where the framework maintains them.
 */
class AdminPasswordResetNotification extends ResetPassword
{
    /**
     * The password broker that governs the back office's reset links.
     */
    private const BROKER = 'admins';

    /**
     * The reset link the email's button points at.
     *
     * `password.reset` belongs to the store front, so the back office uses
     * its own route and the two flows never share a link. The framework's
     * `createUrlUsing` hook is honoured first, the same way the parent
     * class honours it, so an application-level override still wins.
     */
    protected function resetUrl(mixed $notifiable): string
    {
        if (static::$createUrlCallback) {
            return call_user_func(static::$createUrlCallback, $notifiable, $this->token);
        }

        return url(route('admin.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }

    /**
     * The mail body.
     *
     * The framework's version takes the expiry from the default password
     * broker, which is the store front's — not the back office's. This
     * notification reads the admin broker instead, so the number the email
     * quotes is the number the admin link actually lives for.
     */
    protected function buildMailMessage(mixed $url): MailMessage
    {
        return (new MailMessage)
            ->subject(Lang::get('Reset your password'))
            ->line(Lang::get('You are receiving this email because we received a password reset request for your account.'))
            ->action(Lang::get('Reset Password'), $url)
            ->line(Lang::get('This password reset link will expire in :count minutes.', ['count' => config('auth.passwords.'.self::BROKER.'.expire')]))
            ->line(Lang::get('If you did not request a password reset, no further action is required.'));
    }
}
