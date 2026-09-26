<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * ManduVPN RC W-7 (OWNER_DECISIONS.md §F-4): admin listing of issued invite codes.
 *
 * WHY THIS IS NEW CODE. W-7 says reuse before building. Searched first, and there is nothing to reuse on the
 * API side: `git log --all` over '*Invite*' returns only the six upstream commits that created the USER-facing
 * V1\User\InviteController, the InviteCode model and InviteCodeResource, and `--diff-filter=D` finds no deleted
 * admin controller, so an admin invite-code endpoint has never existed on any branch. AdminRoute.php had no
 * invite-code route. The web page active/web/src/app/admin/invite-codes/page.tsx does exist but renders a
 * MOCK_CODES constant with a "TODO: replace with real API data" comment. So the UI is real and the API was the
 * missing half.
 *
 * WHAT IS REUSED: the table (v2_invite_code), the model, the admin fetch shape of CouponController::fetch
 * (current / pageSize, {data, total}), and the admin middleware stack from AdminRoute. No new table, no new
 * column, no new setting, no migration.
 *
 * READ ONLY BY DESIGN. Codes are issued by users through V1\User\InviteController::save and consumed by
 * AuthController::register with a conditional UPDATE. An admin create / revoke endpoint would be a second writer
 * on that single-use flow and is a product decision, not a reporting gap - so this controller only reads.
 *
 * CONTRACT NOTE FOR WEB (the mock is not fully backable): v2_invite_code has no per-code usage counter. Its
 * columns are user_id, code, status and pv. The mock's `usage_count` therefore cannot be served truthfully:
 *   - with invite_never_expire = 0 (the default) a code is single use, so usage is 0 or 1 and `status` IS that
 *     count; this endpoint returns it as `usage_count` and also as `status`.
 *   - with invite_never_expire = 1 a code is reusable and nothing records how often it was used, because
 *     register does not write the code id anywhere. `usage_count` is then null, not 0, and `usage_tracked` is
 *     false so the UI can render "-" instead of inventing a zero.
 * Counting v2_user.invite_user_id would be wrong here: that counts signups per ISSUER, not per CODE, and an
 * issuer can hold several codes.
 */
class InviteCodeController extends Controller
{
    public function fetch(Request $request)
    {
        $request->validate([
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'code' => 'nullable|string|max:32',
            'user_id' => 'nullable|integer|min:1',
            'status' => 'nullable|integer|in:0,1',
        ]);
        $current = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $builder = InviteCode::query();
        if ($request->filled('code')) {
            $builder->where('code', 'like', '%' . $request->input('code') . '%');
        }
        if ($request->filled('user_id')) {
            $builder->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->input('status') !== null) {
            $builder->where('status', (int) $request->input('status'));
        }
        $codes = $builder->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $current);

        // Reusable codes have no usage record at all - see the class comment. Read the setting once, not per row.
        $singleUse = !(int) admin_setting('invite_never_expire', 0);

        // One extra query for the whole page instead of one per row.
        $issuers = User::whereIn('id', collect($codes->items())->pluck('user_id')->filter()->unique()->all())
            ->pluck('email', 'id');

        $data = [];
        foreach ($codes->items() as $c) {
            $data[] = [
                'id' => (int) $c->id,
                'code' => (string) $c->code,
                'user_id' => $c->user_id ? (int) $c->user_id : null,
                // Email, not the whole user row: this endpoint must not widen admin over-exposure (RC_BACKEND.md
                // OD-6). No token, uuid, password_salt or subscribe_url leaves here.
                'issuer_email' => $c->user_id ? ($issuers[$c->user_id] ?? null) : null,
                'status' => (int) $c->status,
                'usage_tracked' => $singleUse,
                'usage_count' => $singleUse ? (int) $c->status : null,
                'pv' => (int) $c->pv,
                'created_at' => (int) $c->created_at,
                'updated_at' => (int) $c->updated_at,
            ];
        }

        return response([
            'data' => $data,
            'total' => $codes->total(),
            // So the UI does not have to guess why usage_count can be null.
            'single_use_mode' => $singleUse,
        ]);
    }
}
