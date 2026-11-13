<?php

namespace App\Http\Controllers\Site;

use App\Actions\ContactActions;
use App\Http\Controllers\Controller;
use App\Site\PostFilters;
use App\Site\SiteLayout;
use App\Support\RequestError;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    public function __invoke(Request $request): View
    {
        return $this->page(sent: (PostFilters::queryParams($request->server->get('QUERY_STRING'))['sent'] ?? null) === '1');
    }

    /**
     * No-JS path: the browser POSTs the form here, the action runs, and the result is rendered (success redirects
     * to `/contact?sent=1`).
     */
    public function submit(Request $request, ContactActions $actions): View|RedirectResponse
    {
        try {
            $actions->send($request);
        } catch (ValidationException $e) {
            return $this->page(
                values: $this->values($request),
                fieldErrors: $e->errors(),
                formError: 'Please fix the highlighted fields and try again.',
            );
        } catch (RequestError) {
            return $this->page(
                values: $this->values($request),
                formError: 'Your message could not be sent. Please try again or email us directly.',
            );
        }

        return redirect('/contact?sent=1', 303);
    }

    /** @return array<string, string> */
    private function values(Request $request): array
    {
        return array_filter($request->only(['name', 'email', 'subject', 'message', 'website']), 'is_string');
    }

    private function page(array $values = [], array $fieldErrors = [], ?string $formError = null, bool $sent = false): View
    {
        return view('site.contact', [
            'layout' => SiteLayout::read(),
            'values' => $values,
            'fieldErrors' => $fieldErrors,
            'formError' => $formError,
            'sent' => $sent,
        ]);
    }
}
