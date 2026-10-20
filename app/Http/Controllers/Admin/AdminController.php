<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminRequest;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        $admins = Admin::orderBy('name')->get();

        return view('admin.admins.index', compact('admins'));
    }

    public function store(AdminRequest $request): RedirectResponse
    {
        Admin::create($request->validated());

        return redirect()->route('admin.admins.index')
            ->with('success', 'Admin created successfully.');
    }

    public function destroy(Admin $admin): RedirectResponse
    {
        $lastActive = Admin::where('is_active', true)->count();

        if ($lastActive <= 1 && $admin->is_active) {
            return back()->withErrors(['admin' => 'Cannot delete the last active admin.']);
        }

        $admin->delete();

        return redirect()->route('admin.admins.index')
            ->with('success', 'Admin deleted successfully.');
    }

    public function toggleActive(Admin $admin): RedirectResponse
    {
        $lastActive = Admin::where('is_active', true)->count();

        if ($admin->is_active && $lastActive <= 1) {
            return back()->withErrors(['admin' => 'Cannot deactivate the last active admin.']);
        }

        $admin->update(['is_active' => ! $admin->is_active]);

        return back()->with('success', 'Admin status updated.');
    }
}
