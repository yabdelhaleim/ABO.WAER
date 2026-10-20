<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLazyLoadingPrevention();
        $this->configureSessionSecurity();
        $this->configureRateLimiters();
    }

    /**
     * Throw on lazy-loaded relations outside production. The point of
     * shipping this in non-production is to make N+1 queries loud during
     * development and testing so they get fixed before they ship.
     */
    protected function configureLazyLoadingPrevention(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    /**
     * Force the session cookie to HTTPS-only in production regardless of
     * what `SESSION_SECURE_COOKIE` resolves to in the environment file.
     * Defence-in-depth: an operator who forgets to set the env var would
     * otherwise ship a session cookie readable over plain HTTP.
     */
    protected function configureSessionSecurity(): void
    {
        if ($this->app->isProduction()) {
            config([
                'session.secure' => true,
                'session.http_only' => true,
                'session.same_site' => 'lax',
            ]);
        }
    }

    /**
     * Register named rate limiters that the route layer references
     * through the `throttle:<name>` middleware.
     *
     * - `login`    — caps credential-stuffing and brute-force attempts
     *               at 5/min keyed by the submitted identifier plus the
     *               client IP, so two bots brute-forcing the same
     *               account from different IPs each get their own budget.
     * - `checkout` — caps order submissions at 3/min to keep script
     *               bots from filling the orders table with junk. Keyed
     *               by customer id when signed in and by IP otherwise.
     * - `coupons`  — caps coupon validation at 10/min/IP because the
     *               endpoint is reachable anonymously and guessing
     *               valid codes is the obvious attack.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $identifier = trim((string) (
                $request->input('identifier')
                ?? $request->input('email')
                ?? $request->input('phone')
                ?? ''
            ));

            return Limit::perMinute(5)->by(strtolower($identifier).'|'.$request->ip());
        });

        RateLimiter::for('checkout', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(3)->by($key !== null ? 'user:'.$key : 'ip:'.$request->ip());
        });

        RateLimiter::for('coupons', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
