<?php

namespace App\Http\Middleware;

use App\Models\License;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'توکن ارسال نشده است'], 401);
        }

        try {
            $secret = env('JWT_SECRET', 'your-secret-key');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $request->attributes->set('user', (array) $decoded);
            return $next($request);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'توکن نامعتبر است',
                'message' => $e->getMessage()
            ], 401);
        }
    }
}
