<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\ConsentService;
use Illuminate\Http\Request;

/**
 * ManduVPN RC: guest terms feed for the signup form (RC_CONTRACTS.md §3.3).
 * GET /api/v1/guest/terms/current?locale=ko|zh|en|ja
 *   -> { version, locale, sections: [ { key, title, body, warnings[], checkbox_label } ] }
 * The client must echo `version` back as `consent_version` when it registers; a stale version is refused by
 * ConsentService::validateRegistration, which is how a terms update forces a re-read.
 * Unauthenticated on purpose - the signup form needs it before an account exists. Read-only.
 */
class TermsController extends Controller
{
    public function current(Request $request)
    {
        $request->validate([
            'locale' => 'nullable|string|max:16',
        ]);
        $locale = ConsentService::normalizeLocale($request->input('locale')) ?? 'ko';
        return $this->success(ConsentService::current($locale));
    }
}
