<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Guest\CommController;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\Guest\PlanController;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Http\Controllers\V1\Guest\TermsController;
use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest'
        ], function ($router) {
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            // Terms (ManduVPN RC: signup consent text, RC_CONTRACTS.md §3.3)
            $router->get('/terms/current', [TermsController::class, 'current']);
            // Telegram
            $router->post('/telegram/webhook', [TelegramController::class, 'webhook']);
            // Payment
            $router->match(['get', 'post'], '/payment/notify/{method}/{uuid}', [PaymentController::class, 'notify']);
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
        });
    }
}
