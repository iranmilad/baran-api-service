<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'website_url' => 'required|url',
            'license_key' => 'required|string'
        ]);

        if ($validator->fails()) {
            Log::warning('Login validation failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        $license = License::where('key', $request->license_key)
            ->where('website_url', $request->website_url)
            ->where('expires_at', '>', now())
            ->first();

        if (!$license) {
            Log::warning('Invalid or expired license', [
                'website_url' => $request->website_url,
                'license_key' => $request->license_key
            ]);
            return response()->json([
                'success' => false,
                'message' => 'لایسنس نامعتبر یا منقضی شده است'
            ], 401);
        }

        // Create custom claims for JWT
        $customClaims = [
            'website_url' => $license->website_url,
            'license_id' => $license->id,
            'user_id' => $license->user_id,
            'account_type' => $license->account_type
        ];

        // Generate token
        $token = JWTAuth::claims($customClaims)->fromUser($license);

        Log::info('Login successful', [
            'license_id' => $license->id,
            'website_url' => $license->website_url,
            'user_id' => $license->user_id,
            'account_type' => $license->account_type
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ورود موفقیت‌آمیز',
            'data' => [
                'token' => $token,
                'expires_at' => JWTAuth::setToken($token)->getPayload()->get('exp'),
                'account_type' => $license->account_type
            ]
        ]);
    }

    public function me(Request $request)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();

            if (!$license) {
                Log::error('License not found in token');
                return response()->json([
                    'success' => false,
                    'message' => 'لایسنس یافت نشد'
                ], 404);
            }

            if (!$license->isActive()) {
                Log::error('License is not active', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'لایسنس فعال نیست'
                ], 403);
            }

            Log::info('License info retrieved', [
                'license_id' => $license->id,
                'website_url' => $license->website_url,
                'user_id' => $license->user_id,
                'account_type' => $license->account_type
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'license' => [
                        'id' => $license->id,
                        'website_url' => $license->website_url,
                        'expires_at' => $license->expires_at,
                        'is_active' => $license->isActive(),
                        'account_type' => $license->account_type
                    ],
                    'user' => $license->user ? [
                        'id' => $license->user->id,
                        'name' => $license->user->name,
                        'email' => $license->user->email
                    ] : null
                ]
            ]);

        } catch (TokenExpiredException $e) {
            Log::error('Token expired', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'توکن منقضی شده است'
            ], 401);
        } catch (TokenInvalidException $e) {
            Log::error('Invalid token', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'توکن نامعتبر است'
            ], 401);
        } catch (\Exception $e) {
            Log::error('Error in me endpoint', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطای سیستمی'
            ], 500);
        }
    }
}
