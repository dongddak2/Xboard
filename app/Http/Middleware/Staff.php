<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Closure;

class Staff
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) throw new ApiException(__('Not logged in or the login has expired'), 403, 'SESSION_EXPIRED');

        $user = AuthService::decryptAuthData($authorization);
        // Two different causes used to share one throw, and the identifier made that a bug: a client that
        // clears the stored session on SESSION_EXPIRED would sign out a perfectly logged-in non-staff user.
        // Decrypt failure really is an expired/forged token; !is_staff is a permission refusal with a live
        // session, so it gets its own identifier and its own sentence.
        if (!$user) throw new ApiException(__('Not logged in or the login has expired'), 403, 'SESSION_EXPIRED');
        if (!$user['is_staff']) throw new ApiException(__('Staff permission required'), 403, 'STAFF_PERMISSION_REQUIRED');
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }
}
