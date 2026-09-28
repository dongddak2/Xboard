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
        });
    }
}
