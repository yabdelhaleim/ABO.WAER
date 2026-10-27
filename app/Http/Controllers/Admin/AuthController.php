<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        // Both keys are always present, even when the request omits them, so
        // the credential lookup can never narrow itself down to the password
        // alone and match an arbitrary account.
        //
        // The address is folded to lower case first, the way it is stored:
        // an account typed as "Admin@Store.com" still finds the row that
        // was saved as "admin@store.com".
        $email = $request->input('email');

        $credentials = [
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
            'password' => $request->input('password'),
        ];

        // A suspended account must never reach the dashboard. `is_active` is
        // a boolean column stored as '1'/'0' text, so folding it into the
        // credentials narrows the lookup to active admins: a suspended one
        // fails the same way a wrong password does — same code path, same
        // message — and the response reveals nothing about whether the
        // account exists.
        $credentials['is_active'] = true;

        if (! Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'Email or password is incorrect.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        // The admins list shows when each account was last used, so the
        // sign-in stamps the row. The write is skipped when the account
        // was not moved in the database — there is nothing to stamp.
        $admin = Auth::guard('admin')->user();

        if ($admin instanceof Admin) {
            $admin->forceFill(['last_login_at' => now()])->save();
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
