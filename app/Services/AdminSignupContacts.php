<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Private contact projection used only by authenticated V2 Admin user endpoints. */
class AdminSignupContacts
{
    public static function attach($users): void
    {
        $users = collect($users);
        $available = Schema::hasTable('v2_user_contact') && Schema::hasColumns('v2_user_contact', ['user_id', 'real_name', 'wechat_id', 'phone_country_code', 'phone_number', 'consent_version', 'consented_at', 'marketing_consent']);
        $contacts = $available && $users->isNotEmpty()
            ? DB::table('v2_user_contact')->whereIn('user_id', $users->pluck('id'))
                ->select(['user_id', 'real_name', 'wechat_id', 'phone_country_code', 'phone_number', 'consent_version', 'consented_at', 'marketing_consent'])
                ->get()->keyBy('user_id') : collect();
        foreach ($users as $user) {
            $contact = $contacts->get($user->id);
            $user->contact_status = !$available ? 'unavailable' : ($contact ? 'recorded' : 'not_recorded');
            $user->signup_contact = $contact ? [
                'real_name' => $contact->real_name,
                'wechat_id' => $contact->wechat_id,
                'phone_country_code' => $contact->phone_country_code,
                'phone_number' => $contact->phone_number,
                'consent_version' => $contact->consent_version,
                'consented_at' => (int) $contact->consented_at,
                'marketing_consent' => (bool) $contact->marketing_consent,
            ] : null;
        }
    }
}
