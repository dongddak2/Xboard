<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ManduVPN RC: admin-managed signup terms (RC_CONTRACTS.md §3.3), on the existing admin "config" route group
 * so it inherits the admin middleware. Deliberately NOT part of the generic ConfigSave: the payload is a
 * validated 4-locale structure, not a flat setting.
 *
 * Saving is all-or-nothing. A payload that is missing a locale or a section, that empties a title/body/checkbox
 * label, that drops the statutory cooling-off / refund-obligation clause, or that states an unconditional refusal
 * to refund is refused with 422 and nothing is written (ConsentService::validateTerms).
 *
 * Raising `version` does not rewrite anyone's stored consent: existing v2_user_consent rows keep the version they
 * were recorded under, and the next signup records the new one. Signups made against the old version are refused
 * with "terms have been updated" until the client re-reads the guest terms API.
 */
class ConsentTermsController extends Controller
{
    public function fetch()
    {
        $terms = ConsentService::terms();
        [, $errors] = ConsentService::validateTerms($terms);
        return $this->success([
            'version' => ConsentService::currentVersion(),
            'locales' => ConsentService::LOCALES,
            'sections' => ConsentService::SECTIONS,
            'terms' => $terms,
            'valid' => !$errors,
            'errors' => array_values($errors),
            'is_default' => !DB::table('v2_settings')->where('name', ConsentService::TERMS_KEY)->exists(),
            'consent_rows_total' => DB::table('v2_user_consent')->count(),
            'consent_rows_current_version' => DB::table('v2_user_consent')
                ->where('consent_version', ConsentService::currentVersion())->count(),
        ]);
    }

    public function save(Request $request)
    {
        $version = trim((string) $request->input('version', ''));
        if ($version === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $version)) {
            throw new ApiException('version must be 1-64 characters of letters, digits, dot, underscore or hyphen', 422);
        }
        [$terms, $errors] = ConsentService::validateTerms($request->input('terms'));
        if ($errors) {
            throw new ApiException(implode('; ', $errors), 422);
        }
        $encoded = json_encode($terms, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new ApiException('terms could not be encoded', 422);
        }
        DB::transaction(function () use ($version, $encoded) {
            $this->putSetting(ConsentService::VERSION_KEY, $version);
            $this->putSetting(ConsentService::TERMS_KEY, $encoded);
        });
        Log::info('ManduVPN RC: signup terms saved', [
            'admin_id' => (int) optional($request->user())->id,
            'version' => $version,
        ]);
        return $this->fetch();
    }

    /** Restore the built-in 4-locale default text (keeps the current version string). */
    public function resetToDefault(Request $request)
    {
        DB::table('v2_settings')->where('name', ConsentService::TERMS_KEY)->delete();
        Log::info('ManduVPN RC: signup terms reset to built-in default', [
            'admin_id' => (int) optional($request->user())->id,
        ]);
        return $this->fetch();
    }

    /** Consent evidence for one user, newest version first. Read-only. */
    public function userHistory(Request $request)
    {
        $request->validate(['user_id' => 'required|integer|min:1']);
        return $this->success(ConsentService::historyFor((int) $request->input('user_id')));
    }

    private function putSetting(string $name, string $value): void
    {
        $row = ['value' => $value, 'updated_at' => now()];
        if (DB::table('v2_settings')->where('name', $name)->exists()) {
            DB::table('v2_settings')->where('name', $name)->update($row);
        } else {
            DB::table('v2_settings')->insert($row + ['group' => 'manduvpn', 'type' => 'input', 'name' => $name, 'created_at' => now()]);
        }
    }
}
