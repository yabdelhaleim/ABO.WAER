<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $phone = $request->input('phone');
        $whatsapp = $request->input('whatsapp') ?: $phone;

        $customer = Customer::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'password' => $request->input('password'),
            'accepts_promotions' => $request->boolean('accepts_promotions'),
        ]);

        Auth::login($customer);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
