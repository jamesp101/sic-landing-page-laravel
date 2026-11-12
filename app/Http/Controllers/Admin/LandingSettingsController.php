<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminPage;
use App\Content\WelcomeHero;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LandingSettingsController extends Controller
{
    public function __invoke(Request $request)
    {
        AdminPage::resolve($request, ['superAdmin' => true]);

        return view('admin.settings.landing', [
            'welcomeHero' => WelcomeHero::getWelcomeHero() ?? '',
        ]);
    }
}
