<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Content\Content;
use App\Content\ContentField;
use App\Content\WelcomeHero;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `settings.*` actions (JSON). */
class SettingsActions
{
    #[Action(Access::Superadmin)]
    public function updateLanding(Request $request): array
    {
        $input = ActionInput::validate($request, ['welcomeHeroContent' => ActionField::text(ContentField::rules(false))]);
        WelcomeHero::setWelcomeHero(Content::forStorage($input['welcomeHeroContent'])->value);
        Flash::success('Landing page settings updated successfully.');

        return ['saved' => true];
    }
}
