<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRequest;
use App\Models\Admin;
use App\Models\AdminActivity;
use App\Services\Admin\AdminActivityService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Managing the back-office accounts.
 *
 * Four rules are enforced here, on the server, because none of them can
 * be left to the interface:
 *
 *  1. Only an owner may reach these actions at all. The route carries
 *     the `admin.permission:admins` middleware, and the matrix in
 *     App\Enums\AdminRole gives the "manage" level on that module to the
 *     owner alone — the check below repeats it, so the rule survives
 *     even if the route is ever re-wired.
 *  2. An admin may not remove or suspend the account they are working
 *     from.
 *  3. The last active admin may not be removed or suspended — the panel
 *     must never end up without a way in.
 *  4. The last active owner may not be removed, suspended or demoted —
 *     the panel must never end up without an owner either.
 *
 * Rules 3 and 4 are counted inside a transaction that locks the owner
 * rows first, so two requests that arrive at the same time cannot both
 * decide that a second owner may go.
 *
 * Every action answers in whichever shape the caller asked for: a JSON
 * body for the page's own script, a redirect for a plain form post.
 */
class AdminController extends Controller
{
    public function __construct(protected AdminActivityService $activity) {}

    /**
     * The admins list, read straight from the database.
     *
     * The payload the page hydrates from is built here rather than in
     * the view: the view only serialises what it is given, and the
     * shape is the same contract the front-end script reads.
     */
    public function index(): View
    {
        $currentAdminId = Auth::guard('admin')->id();

        $admins = Admin::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Admin $admin): array => [
                'id' => (string) $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role->value,
                'status' => $admin->is_active ? 'active' : 'suspended',
                'lastLogin' => $admin->last_login_at?->toIso8601String(),
                'signedIn' => $admin->id === $currentAdminId,
            ])
            ->values();

        $roles = [];

        foreach (AdminRole::cases() as $role) {
            $roles[$role->value] = [
                'label' => $role->label(),
                'description' => $role->description(),
            ];
        }

        $permissions = [];

        foreach (AdminRole::cases() as $role) {
            $permissions[$role->value] = $role->permissions();
        }

        $activity = $this->activity->recent(50)
            ->map(fn (AdminActivity $row): array => [
                'id' => (string) $row->id,
                'adminId' => $row->admin_id !== null ? (string) $row->admin_id : null,
                'adminName' => $row->admin?->name ?? 'System',
                'action' => $row->action,
                'subjectType' => $row->subject_type,
                'subjectId' => $row->subject_id !== null ? (string) $row->subject_id : null,
                'at' => $row->created_at?->toIso8601String(),
            ])
            ->values();

        return view('admin.admins.index', [
            'admins' => $admins,
            'roles' => $roles,
            'permissions' => $permissions,
            'modules' => AdminRole::modules(),
            'moduleLabels' => AdminRole::MODULE_LABELS,
            'activity' => $activity,
            'currentAdminId' => $currentAdminId,
            'routes' => [
                'store' => route('admin.admins.store'),
                'update' => route('admin.admins.update', ['admin' => '__ADMIN__']),
                'toggle' => route('admin.admins.toggle', ['admin' => '__ADMIN__']),
                'destroy' => route('admin.admins.destroy', ['admin' => '__ADMIN__']),
                'passwordEmail' => route('admin.password.email'),
            ],
        ]);
    }

    /**
     * Create an account.
     *
     * The address arrives folded to lower case by the request, and the
     * role is validated against the enum, so the two columns can only
     * ever hold values the application knows how to act on.
     */
    public function store(AdminRequest $request): JsonResponse|RedirectResponse
    {
        $this->guardOwnerAction();

        $data = $request->validated();

        $admin = Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => true,
        ]);

        $this->activity->record('admin.created', $admin, [
            'role' => $admin->role->value,
        ]);

        return $this->success($request, 'Admin created successfully.');
    }

    /**
     * Edit an account: name, address, role and — when one is given — a
     * new password.
     *
     * The role may be changed by the account holder themselves: an owner
     * who wants to hand the panel over needs a second owner in place
     * first, and the rule below is what refuses the step that would
     * leave the panel without one.
     */
    public function update(AdminRequest $request, Admin $admin): JsonResponse|RedirectResponse
    {
        $this->guardOwnerAction();

        $data = $request->validated();
        $role = AdminRole::from($data['role']);

        $demotingOwner = $admin->role === AdminRole::Owner
            && $role !== AdminRole::Owner
            && $admin->is_active;

        if ($demotingOwner) {
            return $this->executeWithActiveOwnersLock(function () use ($request, $admin, $data, $role) {
                $refused = $this->guardLastActiveOwner(
                    $admin,
                    'You cannot demote the last active owner.',
                    $request
                );

                if ($refused !== null) {
                    return $refused;
                }

                $admin->fill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'role' => $role->value,
                ]);

                if (! empty($data['password'])) {
                    $admin->password = $data['password'];
                }

                $admin->save();

                $this->activity->record('admin.updated', $admin, [
                    'role' => $role->value,
                ]);

                return $this->success($request, 'Admin updated successfully.');
            }, $request);
        }

        $admin->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $role->value,
        ]);

        if (! empty($data['password'])) {
            $admin->password = $data['password'];
        }

        $admin->save();

        $this->activity->record('admin.updated', $admin, [
            'role' => $role->value,
        ]);

        return $this->success($request, 'Admin updated successfully.');
    }

    /**
     * Remove an account.
     */
    public function destroy(Request $request, Admin $admin): JsonResponse|RedirectResponse
    {
        $this->guardOwnerAction();

        if ($admin->id === Auth::guard('admin')->id()) {
            return $this->refuse('admin', 'Cannot delete your own account.', $request);
        }

        return $this->executeWithActiveOwnersLock(function () use ($request, $admin) {
            if ($admin->is_active && Admin::where('is_active', true)->whereKeyNot($admin->getKey())->count() < 1) {
                return $this->refuse('admin', 'Cannot delete the last active admin.', $request);
            }

            if ($admin->is_active && $admin->role === AdminRole::Owner) {
                $refused = $this->guardLastActiveOwner(
                    $admin,
                    'Cannot delete the last active owner.',
                    $request
                );

                if ($refused !== null) {
                    return $refused;
                }
            }

            $name = $admin->name;
            $id = $admin->id;

            $admin->delete();

            $this->activity->record('admin.deleted', null, [
                'name' => $name,
                'admin_id' => $id,
            ]);

            return $this->success($request, 'Admin deleted successfully.');
        }, $request);
    }

    /**
     * Switch an account between active and suspended.
     */
    public function toggleActive(Request $request, Admin $admin): JsonResponse|RedirectResponse
    {
        $this->guardOwnerAction();

        if ($admin->id === Auth::guard('admin')->id()) {
            return $this->refuse('admin', 'Cannot deactivate your own account.', $request);
        }

        if (! $admin->is_active) {
            $admin->update(['is_active' => true]);

            $this->activity->record('admin.activated', $admin);

            return $this->success($request, 'Admin status updated.');
        }

        return $this->executeWithActiveOwnersLock(function () use ($request, $admin) {
            if (Admin::where('is_active', true)->whereKeyNot($admin->getKey())->count() < 1) {
                return $this->refuse('admin', 'Cannot deactivate the last active admin.', $request);
            }

            if ($admin->role === AdminRole::Owner) {
                $refused = $this->guardLastActiveOwner(
                    $admin,
                    'Cannot deactivate the last active owner.',
                    $request
                );

                if ($refused !== null) {
                    return $refused;
                }
            }

            $admin->update(['is_active' => false]);

            $this->activity->record('admin.suspended', $admin);

            return $this->success($request, 'Admin status updated.');
        }, $request);
    }

    /**
     * Refuse the action unless the signed-in admin is an owner.
     *
     * The route already carries the `admin.permission:admins` middleware,
     * which gives the "manage" level on that module to the owner alone.
     * The check is repeated here so the rule does not depend on the
     * route's wiring staying exactly as it is today.
     */
    protected function guardOwnerAction(): void
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof Admin || ! $admin->role->canManage('admins')) {
            abort(403, 'Only an owner may manage the back-office accounts.');
        }
    }

    /**
     * Run a critical operation that reduces active admins/owners within a shared
     * atomic lock and database transaction, guaranteeing pessimistic row locking
     * and mutual exclusion across concurrent requests.
     */
    protected function executeWithActiveOwnersLock(\Closure $callback, Request $request): mixed
    {
        try {
            return Cache::lock('admin_active_owners_lock', 10)->block(5, function () use ($callback) {
                return DB::transaction(function () use ($callback) {
                    Admin::query()
                        ->where('role', AdminRole::Owner->value)
                        ->lockForUpdate()
                        ->get(['id']);

                    return $callback();
                });
            });
        } catch (LockTimeoutException) {
            return $this->refuse('admin', 'Another administration change is in progress. Please retry.', $request);
        }
    }

    /**
     * Refuse the action when it would take the last active owner away.
     *
     * The account being acted on is left out of the count, because the
     * action is about to take it out of the picture — that is the whole
     * question being asked. Zero remaining owners means the panel would
     * be left without one.
     *
     * @return JsonResponse|RedirectResponse|null the refusal, or null
     *                                            when the action may go
     *                                            ahead
     */
    protected function guardLastActiveOwner(Admin $admin, string $message, Request $request): JsonResponse|RedirectResponse|null
    {
        $remainingOwners = Admin::query()
            ->where('role', AdminRole::Owner->value)
            ->where('is_active', true)
            ->when(
                $admin->is_active,
                fn ($query) => $query->whereKeyNot($admin->getKey()),
            )
            ->count();

        if ($remainingOwners < 1) {
            return $this->refuse('admin', $message, $request);
        }

        return null;
    }

    /**
     * The answer for a successful action.
     */
    protected function success(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return redirect()->route('admin.admins.index')->with('success', $message);
    }

    /**
     * The answer for a refused action.
     */
    protected function refuse(string $key, string $message, Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => [$key => [$message]],
            ], 422);
        }

        return back()->withErrors([$key => $message]);
    }
}
