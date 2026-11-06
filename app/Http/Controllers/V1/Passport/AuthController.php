<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthLogin;
use App\Services\Auth\LoginService;
use App\Services\AuthService;

class AuthController extends Controller
{
    public function login(AuthLogin $request, LoginService $service)
    {
        [$success, $result] = $service->login($request->validated('email'), $request->validated('password'));
        return $success ? $this->success((new AuthService($result))->generateAuthData()) : $this->fail($result);
    }
}
