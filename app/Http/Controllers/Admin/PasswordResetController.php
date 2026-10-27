<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin back office's password recovery flow.
 *
 * The flow mirrors Laravel's standard password reset, with two differences
 * that keep it on the back office's own rails:
 *
 *   - every broker call names the `admins` broker, so the tokens live in
 *     `admin_password_reset_tokens` and are looked up through the admin
 *     provider rather than the store front's;
 *   - the two routes that a signed-out admin needs carry `admin.` names and
 *     paths of their own, so the store front's `password.*` routes stay
 *     untouched and the two flows never share a link.
 *
 * Neither the request form nor the reset form tells an anonymous visitor
 * whether an address belongs to an admin: the request endpoint answers the
 * same way for every address, and the update endpoint reports one message
 * for every rejected token.
 */
class PasswordResetController extends Controller
{
    /**
     * The password broker that owns the back office's reset tokens.
     */
    private const BROKER = 'admins';

    /**
     * Show the form that asks for the address to send a reset link to.
     */
    public function showLinkRequestForm(): View
    {
        return view('admin.password.request');
    }

    /**
     * Send the reset link.
     *
     * The broker's answer is deliberately not surfaced: an address that
     * belongs to an admin, one that does not, and one that was asked for a
     * moment ago all leave with the same message, so the endpoint cannot be
     * used to discover which addresses exist.
     */
    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
        ]);

        Password::broker(self::BROKER)->sendResetLink($credentials);

        return back()->with(
            'status',
            'If that address belongs to an admin account, a password reset link is on its way.'
        );
    }

    /**
     * Show the form that takes the new password.
     *
     * The address travels as a query parameter next to the token, exactly
     * the way the notification builds the link, so the form can hand both
     * back unchanged when the new password is submitted.
     */
    public function showResetForm(Request $request, string $token): View
    {
        return view('admin.password.reset', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Store the new password.
     */
    public function reset(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            $credentials,
            function (Admin $admin, string $password): void {
                $admin->forceFill([
                    // The model's `hashed` cast turns the plain password into
                    // a hash on its way into the database.
                    'password' => $password,
                    // A fresh remember token retires the "remember me" cookie
                    // that was minted alongside the old password.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($admin));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One message for every rejection — an unknown address, a
            // mistyped address and an expired token all read the same, so
            // the form cannot be used to probe which addresses are admins.
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'This password reset link is invalid or has expired.',
                ]);
        }

        return redirect()
            ->route('admin.login')
            ->with('status', 'Your password has been reset. Sign in with the new one.');
    }
}
