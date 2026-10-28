<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google and authenticate.
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google sign-in callback failed: '.$e->getMessage());

            return redirect()->route('login')->withErrors([
                'identifier' => 'Google sign-in was canceled or failed. Please try again.',
            ]);
        }

        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();
        $name = $googleUser->getName() ?? $googleUser->getNickname() ?? 'Customer';

        // 1. Try to find customer by google_id
        $customer = Customer::where('google_id', $googleId)->first();

        // 2. Or by email if already registered with email/password
        if (! $customer && $email) {
            $customer = Customer::where('email', $email)->first();

            if ($customer) {
                // Link google_id to existing account
                $customer->update(['google_id' => $googleId]);
            }
        }

        // 3. Or create brand-new customer
        if (! $customer) {
            $customer = Customer::create([
                'name' => $name,
                'email' => $email,
                'google_id' => $googleId,
                'accepts_promotions' => true,
            ]);
        }

        if ($customer->is_blocked) {
            return redirect()->route('login')->withErrors([
                'identifier' => 'This account is blocked. Reason: '.($customer->block_reason ?? 'Administrative action.'),
            ]);
        }

        Auth::guard('web')->login($customer, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
