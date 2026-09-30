<?php
namespace App\Http\Routes\V2;

use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', 'V1\\Passport\\AuthController@register');
            $router->post('/auth/login', 'V1\\Passport\\AuthController@login');
            $router->get ('/auth/token2Login', 'V1\\Passport\\AuthController@token2Login');
            $router->post('/auth/forget', 'V1\\Passport\\AuthController@forget');
            // getQuickLoginUrl is deliberately NOT registered here. It converts a bearer token into a
            // panel login code, so it must sit behind auth: the one registration is UserRoute.php ->
            // V1\User\UserController@getQuickLoginUrl, inside the 'user' middleware group. See that method.
            $router->post('/auth/loginWithMailLink', 'V1\\Passport\\AuthController@loginWithMailLink');
            // Comm
            $router->post('/comm/sendEmailVerify', 'V1\\Passport\\CommController@sendEmailVerify');
            $router->post('/comm/pv', 'V1\\Passport\\CommController@pv');
        });
    }
}
