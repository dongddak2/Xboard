<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Server\DeepbworkController;
use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
use App\Http\Controllers\V1\Server\UniProxyController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server',
        ], function ($router) {
            $router->group([ // ManduVPN PP-C4-1C: IKEv2 node API (per-node token, IkeV2NodeAuth)
                'prefix' => 'manduvpn/ikev2',
                'middleware' => \App\Http\Middleware\IkeV2NodeAuth::class,
            ], function ($route) {
                $route->get('users', [\App\Http\Controllers\V1\Server\IkeV2NodeApiController::class, 'users']);
                $route->post('report', [\App\Http\Controllers\V1\Server\IkeV2NodeApiController::class, 'report']);
                $route->get('ping', [\App\Http\Controllers\V1\Server\IkeV2NodeApiController::class, 'ping']);
            });
            $router->group([
                'prefix' => 'UniProxy',
                'middleware' => 'server'
            ], function ($route) {
                $route->get('config', [UniProxyController::class, 'config']);
                $route->get('user', [UniProxyController::class, 'user']);
                $route->post('push', [UniProxyController::class, 'push']);
                $route->post('alive', [UniProxyController::class, 'alive']);
                $route->get('alivelist', [UniProxyController::class, 'alivelist']);
            });
            $router->group([
                'prefix' => 'ShadowsocksTidalab',
                'middleware' => 'server:shadowsocks'
            ], function ($route) {
                $route->get('user', [ShadowsocksTidalabController::class, 'user']);
                $route->post('submit', [ShadowsocksTidalabController::class, 'submit']);
            });
            $router->group([
                'prefix' => 'TrojanTidalab',
                'middleware' => 'server:trojan'
            ], function ($route) {
                $route->get('config', [TrojanTidalabController::class, 'config']);
                $route->get('user', [TrojanTidalabController::class, 'user']);
                $route->post('submit', [TrojanTidalabController::class, 'submit']);
            });
        });
    }
}
