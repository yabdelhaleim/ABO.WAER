<?php

namespace App\Http\Middleware;

use App\Models\StoreSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the storefront shut while the store is in maintenance mode.
 *
 * The switch lives in the admin settings. While it is on, a customer
 * gets a one-line page saying the store is closed instead of the shop;
 * the back office keeps working, because the orders keep coming in
 * through the admin.
 */
class EnsureStoreIsOpen
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! StoreSetting::get('store.maintenance', false)) {
            return $next($request);
        }

        // The back office stays open — the person who turned the switch
        // on is usually signing in to turn it off again — and so does
        // the health check.
        if ($request->is('admin', 'admin/*', 'up')) {
            return $next($request);
        }

        return response()->view('maintenance', [
            'storeName' => (string) (StoreSetting::get('store.name') ?: 'ABO'),
        ], 503, ['Retry-After' => '3600']);
    }
}
