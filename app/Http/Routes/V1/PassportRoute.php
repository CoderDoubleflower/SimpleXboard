<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\Passport\AuthController;
use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->post('passport/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    }
}
