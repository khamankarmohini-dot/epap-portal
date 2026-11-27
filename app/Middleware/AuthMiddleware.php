<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;

class AuthMiddleware
{
    public static function csrf(): callable
    {
        return function (Request $req): void {
            Csrf::verify($req);
        };
    }

    public static function role(string|array $roles): callable
    {
        return function (Request $req) use ($roles): void {
            Csrf::verify($req);
            Auth::requireRole($roles);
        };
    }

    public static function authOnly(): callable
    {
        return function (Request $req): void {
            Csrf::verify($req);
            Auth::requireAuth();
        };
    }
}
