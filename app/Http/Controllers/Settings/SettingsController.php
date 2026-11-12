<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function profile(Request $request): View
    {
        return view('settings.profile', ['user' => $request->user()]);
    }

    public function security(): View
    {
        return view('settings.security');
    }

    public function appearance(): View
    {
        return view('settings.appearance');
    }
}
