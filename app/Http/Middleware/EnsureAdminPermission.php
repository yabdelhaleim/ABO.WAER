<?php

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the role's access to a back-office module.
 *
 * The admin interface used to describe permissions in JavaScript only:
 * the role chips and the 15x5 matrix told the operator what a role
 * could do, but every route under /admin was reachable by every
 * signed-in admin. Hiding a button is a convenience, not a protection —
 * so the rules live here, on the server, and the interface only
 * mirrors them.
 *
 * The middleware takes the module as its parameter:
 *
 *     ->middleware('admin.permission:products')
 *
 * A read request (GET / HEAD / OPTIONS) needs the role to reach the
 * module at all; a write request (POST / PUT / PATCH / DELETE) needs
 * the "manage" level. The levels themselves live in App\Enums\AdminRole,
 * which is the same map the interface draws its role chips from.
 *
 * It runs behind the `admin` middleware, which has already closed the
 * session of a suspended account, so the admin resolved here is always
 * an active one. The guest check below is a second line of defence: a
 * route that carries this middleware without `admin` still may not be
 * served to an anonymous caller.
 */
class EnsureAdminPermission
{
    /**
     * The HTTP verbs that change data and therefore need the "manage"
     * level rather than the "view" one.
     *
     * @var array<int, string>
     */
    protected const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        $role = $admin->role;

        if (! $this->isAllowed($role, $module, $request)) {
            // A write attempt is refused outright; a read attempt sends
            // the admin back to the dashboard with a message, because
            // the module simply is not part of their job.
            abort(403, 'Your role does not allow access to this module.');
        }

        return $next($request);
    }

    /**
     * Whether the role may serve this request against this module.
     */
    protected function isAllowed(AdminRole $role, string $module, Request $request): bool
    {
        $required = $this->requiredLevel($request);

        return $role->levelFor($module) >= $required;
    }

    /**
     * The access level this request needs: "manage" for a write, "view"
     * for a read.
     */
    protected function requiredLevel(Request $request): int
    {
        return in_array($request->method(), self::WRITE_METHODS, true) ? 2 : 1;
    }
}
