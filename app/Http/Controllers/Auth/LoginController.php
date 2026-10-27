<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(protected CartService $cart) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $remember = $request->boolean('remember');
        $credentials = $this->credentialsFor($request);

        // Captured before the credential check runs. Auth::attempt() logs the
        // user in, and SessionGuard::login() migrates the session id — so by
        // the time this method got past the attempt below, the id that owns
        // the guest cart would already be gone. An unused capture on a failed
        // attempt costs nothing.
        $guestSessionId = $request->session()->getId();

        if (! Auth::attempt($credentials, $remember)) {
            $errorKey = $request->submittedField();
            $errorMessage = $errorKey === 'phone'
                ? 'Phone number or password is incorrect.'
                : 'Email, phone, or password is incorrect.';

            return back()
                ->withErrors([$errorKey => $errorMessage])
                ->withInput($request->only($errorKey));
        }

        $request->session()->regenerate();

        $this->cart->mergeGuestCart(Auth::user(), $guestSessionId);

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Build the credentials array for the auth attempt.
     *
     * The `is_blocked` column is folded into the lookup, so a blocked
     * account cannot sign in at all: the attempt fails exactly the way
     * a wrong password does — same code path, same message — and the
     * answer reveals nothing about whether the account exists.
     *
     * @return array<string, mixed>
     */
    protected function credentialsFor(LoginRequest $request): array
    {
        $password = (string) $request->input('password');

        if (! empty($request->input('email'))) {
            return [
                'email' => $request->input('email'),
                'password' => $password,
                'is_blocked' => false,
            ];
        }

        if (! empty($request->input('phone'))) {
            return [
                'phone' => $request->input('phone'),
                'password' => $password,
                'is_blocked' => false,
            ];
        }

        $identifier = (string) $request->input('identifier');
        $key = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        return [$key => $identifier, 'password' => $password, 'is_blocked' => false];
    }
}
