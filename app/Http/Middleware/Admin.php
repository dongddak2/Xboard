<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Auth;
use Closure;

class Admin
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
        if (!Auth::guard('sanctum')->check()) {
            throw new ApiException(__('Not logged in or the login has expired'), 403, 'SESSION_EXPIRED');
        }

        $user = Auth::guard('sanctum')->user();
        if (!$user->is_admin) {
            throw new ApiException('无管理员权限', 403);
        }

        return $next($request);
    }
}
