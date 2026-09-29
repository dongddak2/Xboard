<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

/**
 * ManduVPN stage C: the app's VPN session routes.
 *
 * A SEPARATE FILE, NOT UserRoute, BECAUSE OF THE PATH. The app calls POST /api/v1/vpn/sessions
 * (ApiClient.baseUrl + '/vpn/sessions'), and every route in UserRoute.php sits under the `user` prefix, which
 * would make it /api/v1/user/vpn/sessions - a path the app never requests. RouteServiceProvider globs this
 * directory, so a new file is registered with no other change.
 *
 * The app must therefore be built with --dart-define=MANDUVPN_VPN_API_BASE_URL=https://cyber.manduvpn.kr/api/v1
 * - WITH the /api/v1 suffix. Without it the request lands on /vpn/sessions, which is not a route.
 */
class VpnRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'vpn',
            'middleware' => 'user'
        ], function ($router) {
            $router->post('/sessions', 'V1\Vpn\VpnSessionController@create');
            // The session lifecycle. The path shapes are the app's, not ours: vpn_session_api.dart:80 and :91
            // build '/vpn/sessions/$sessionId/heartbeat' and '/vpn/sessions/$sessionId'.
            //
            // {sessionId} IS A PLAIN STRING PARAMETER, NOT A MODEL. SubstituteBindings is commented out of the
            // api middleware group (Kernel.php:42-52), so route parameters arrive as strings and an
            // implicit-binding type hint would silently receive nothing. The controller looks the row up
            // itself, scoped to the caller's own user_id.
            $router->post('/sessions/{sessionId}/heartbeat', 'V1\Vpn\VpnSessionController@heartbeat');
            $router->delete('/sessions/{sessionId}', 'V1\Vpn\VpnSessionController@destroy');
            // A UI hint, re-verified by POST /sessions before anything is granted. See the controller note.
            $router->get('/entitlements', 'V1\Vpn\VpnEntitlementController@show');
        });
    }
}
