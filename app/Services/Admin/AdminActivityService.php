<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the back office's audit trail.
 *
 * Every mutating admin action records a row here: who did it, what
 * they did, which row they did it to, and whatever else is worth
 * remembering (the reason a customer was blocked, the old and the new
 * value of a field, the ids a bulk action touched).
 *
 * The service is deliberately quiet about failures. An audit row that
 * cannot be written must never roll back the change it was describing
 * — the operator's work is more important than the log — so the write
 * is wrapped and a failure is reported to the application log instead
 * of to the operator.
 */
class AdminActivityService
{
    /**
     * Record an action by the admin behind the current session.
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, ?Model $subject = null, array $meta = [], ?Request $request = null): ?AdminActivity
    {
        $admin = $this->currentAdmin();

        if ($admin === null && $meta === []) {
            // Nothing to attribute the action to and nothing to remember
            // about it — there is no point in a row that says nothing.
            return null;
        }

        try {
            return AdminActivity::create([
                'admin_id' => $admin?->id,
                'action' => $action,
                'subject_type' => $subject !== null ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'meta' => $meta === [] ? null : $meta,
                'ip' => $this->ipAddress($request),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The most recent actions, newest first.
     *
     * @return Collection<int, AdminActivity>
     */
    public function recent(int $limit = 20)
    {
        return AdminActivity::query()
            ->with('admin:id,name,email')
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The most recent actions performed on one row.
     *
     * @return Collection<int, AdminActivity>
     */
    public function recentFor(Model $subject, int $limit = 20)
    {
        return AdminActivity::query()
            ->with('admin:id,name,email')
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The admin behind the current session, if there is one.
     */
    protected function currentAdmin(): ?Admin
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin ? $admin : null;
    }

    /**
     * The caller's address, for the trail.
     */
    protected function ipAddress(?Request $request): ?string
    {
        $request ??= request();

        return $request->ip();
    }
}
