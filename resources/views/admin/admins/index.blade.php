@extends('layouts.admin')

@section('title', 'Admins')

@section('content')
<h2>Admins</h2>

<form method="POST" action="{{ route('admin.admins.store') }}">
    @csrf
    <input type="text" name="name" placeholder="Name" required>
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Password" required>
    <input type="password" name="password_confirmation" placeholder="Confirm password" required>
    <button type="submit">Add Admin</button>
</form>

<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($admins as $admin)
        <tr>
            <td>{{ $admin->name }}</td>
            <td>{{ $admin->email }}</td>
            <td>{{ $admin->is_active ? 'Active' : 'Inactive' }}</td>
            <td>
                <form method="POST" action="{{ route('admin.admins.toggle', $admin) }}" style="display:inline">
                    @csrf
                    @method('PATCH')
                    <button type="submit">
                        {{ $admin->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.admins.destroy', $admin) }}" style="display:inline"
                      onsubmit="return confirm('Delete this admin?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
