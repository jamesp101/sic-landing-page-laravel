<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Content\Content;
use App\Content\ContentField;
use App\Content\WelcomeHero;
use App\Support\Authz;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `settings.*` actions (JSON). */
class SettingsActions
{
    public function updateLanding(Request $request): array
    {
        $input = ActionInput::validate($request, ['welcomeHeroContent' => 'text'], [
            'welcomeHeroContent' => ContentField::rules(false),
        ]);
        Authz::requireSuperAdmin($request->user());
        $content = $input['welcomeHeroContent'] === '' ? '' : Content::checkContent($input['welcomeHeroContent'])['value'];
        WelcomeHero::setWelcomeHero($content);
        Flash::success('Landing page settings updated successfully.');

        return ['saved' => true];
    }
}
