<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class PermissionController extends Controller
{
    /**
     * Seed permissions into database
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function seed(Request $request)
    {
        try {
            // دریافت و اعتبارسنجی توکن JWT
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('توکن در درخواست ارسال نشده است');
                return response()->json([
                    'success' => false,
                    'message' => 'توکن ارسال نشده است'
                ], 401);
            }

            try {
                // تلاش برای احراز هویت لایسنس با توکن
                $license = JWTAuth::parseToken()->authenticate();
                if (!$license) {
                    Log::error('توکن نامعتبر - لایسنس یافت نشد');
                    return response()->json([
                        'success' => false,
                        'message' => 'توکن نامعتبر - لایسنس یافت نشد'
                    ], 401);
                }

                if (!$license->isActive()) {
                    Log::error('لایسنس فعال نیست', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'لایسنس فعال نیست'
                    ], 403);
                }

                // دریافت کاربر مرتبط با لایسنس
                $user = $license->user;
                if (!$user) {
                    Log::error('کاربر مرتبط با لایسنس یافت نشد', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'کاربر مرتبط با لایسنس یافت نشد'
                    ], 404);
                }

                // بررسی دسترسی ادمین
                if (!$user->is_admin) {
                    Log::warning('کاربر دسترسی ادمین ندارد', [
                        'user_id' => $user->id,
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'شما دسترسی لازم برای انجام این عملیات را ندارید'
                    ], 403);
                }

            } catch (\Exception $e) {
                Log::error('خطا در احراز هویت توکن', [
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در احراز هویت توکن'
                ], 401);
            }

            // Validate incoming request
            $validator = Validator::make($request->all(), [
                'permissions' => 'required|array',
                'permissions.*.name' => 'required|string',
                'permissions.*.slug' => 'required|string',
                'permissions.*.description' => 'nullable|string',
                'permissions.*.group' => 'nullable|string',
                'permissions.*.is_active' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در اعتبارسنجی داده‌ها',
                    'errors' => $validator->errors()
                ], 422);
            }

            $permissions = $request->input('permissions');
            $createdCount = 0;
            $updatedCount = 0;
            $errors = [];

            DB::beginTransaction();

            foreach ($permissions as $permissionData) {
                try {
                    // Check if permission already exists
                    $existingPermission = Permission::where('slug', $permissionData['slug'])->first();

                    if ($existingPermission) {
                        // Update existing permission
                        $existingPermission->update([
                            'name' => $permissionData['name'],
                            'description' => $permissionData['description'] ?? null,
                            'group' => $permissionData['group'] ?? null,
                            'is_active' => $permissionData['is_active'] ?? true,
                        ]);
                        $updatedCount++;
                    } else {
                        // Create new permission
                        Permission::create([
                            'name' => $permissionData['name'],
                            'slug' => $permissionData['slug'],
                            'description' => $permissionData['description'] ?? null,
                            'group' => $permissionData['group'] ?? null,
                            'is_active' => $permissionData['is_active'] ?? true,
                        ]);
                        $createdCount++;
                    }
                } catch (\Exception $e) {
                    $errors[] = [
                        'slug' => $permissionData['slug'],
                        'error' => $e->getMessage()
                    ];
                    Log::error('Permission seed error: ' . $e->getMessage(), [
                        'permission' => $permissionData
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'دسترسی‌ها با موفقیت ذخیره شدند',
                'data' => [
                    'created' => $createdCount,
                    'updated' => $updatedCount,
                    'errors' => $errors
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission seed failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'خطا در ذخیره‌سازی دسترسی‌ها',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all permissions
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $permissions = Permission::where('is_active', true)
                ->orderBy('group')
                ->orderBy('name')
                ->get()
                ->groupBy('group');

            return response()->json([
                'success' => true,
                'data' => $permissions
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get permissions failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت دسترسی‌ها',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign permissions to a user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignToUser(Request $request)
    {
        try {
            // دریافت و اعتبارسنجی توکن JWT
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('توکن در درخواست ارسال نشده است');
                return response()->json([
                    'success' => false,
                    'message' => 'توکن ارسال نشده است'
                ], 401);
            }

            try {
                // تلاش برای احراز هویت لایسنس با توکن
                $license = JWTAuth::parseToken()->authenticate();
                if (!$license) {
                    Log::error('توکن نامعتبر - لایسنس یافت نشد');
                    return response()->json([
                        'success' => false,
                        'message' => 'توکن نامعتبر - لایسنس یافت نشد'
                    ], 401);
                }

                if (!$license->isActive()) {
                    Log::error('لایسنس فعال نیست', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'لایسنس فعال نیست'
                    ], 403);
                }

                // دریافت کاربر مرتبط با لایسنس
                $authUser = $license->user;
                if (!$authUser) {
                    Log::error('کاربر مرتبط با لایسنس یافت نشد', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'کاربر مرتبط با لایسنس یافت نشد'
                    ], 404);
                }

                // بررسی دسترسی ادمین
                if (!$authUser->is_admin) {
                    Log::warning('کاربر دسترسی ادمین ندارد', [
                        'user_id' => $authUser->id,
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'شما دسترسی لازم برای انجام این عملیات را ندارید'
                    ], 403);
                }

            } catch (\Exception $e) {
                Log::error('خطا در احراز هویت توکن', [
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در احراز هویت توکن'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'permission_slugs' => 'required|array',
                'permission_slugs.*' => 'required|string|exists:permissions,slug',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در اعتبارسنجی داده‌ها',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::find($request->input('user_id'));
            $permissionSlugs = $request->input('permission_slugs');

            // Get permission IDs from slugs
            $permissions = Permission::whereIn('slug', $permissionSlugs)
                ->where('is_active', true)
                ->pluck('id');

            // Sync permissions (this will add new and remove old permissions)
            $user->permissions()->sync($permissions);

            Log::info('Permissions assigned to user', [
                'user_id' => $user->id,
                'permission_slugs' => $permissionSlugs
            ]);

            return response()->json([
                'success' => true,
                'message' => 'دسترسی‌ها با موفقیت به کاربر اختصاص داده شدند',
                'data' => [
                    'user_id' => $user->id,
                    'permissions' => $user->permissions
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Assign permissions to user failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'خطا در اختصاص دسترسی‌ها به کاربر',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user permissions
     *
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserPermissions(Request $request, $userId)
    {
        try {
            // دریافت و اعتبارسنجی توکن JWT
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('توکن در درخواست ارسال نشده است');
                return response()->json([
                    'success' => false,
                    'message' => 'توکن ارسال نشده است'
                ], 401);
            }

            try {
                // تلاش برای احراز هویت لایسنس با توکن
                $license = JWTAuth::parseToken()->authenticate();
                if (!$license) {
                    Log::error('توکن نامعتبر - لایسنس یافت نشد');
                    return response()->json([
                        'success' => false,
                        'message' => 'توکن نامعتبر - لایسنس یافت نشد'
                    ], 401);
                }

                if (!$license->isActive()) {
                    Log::error('لایسنس فعال نیست', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'لایسنس فعال نیست'
                    ], 403);
                }

                // دریافت کاربر مرتبط با لایسنس
                $authUser = $license->user;
                if (!$authUser) {
                    Log::error('کاربر مرتبط با لایسنس یافت نشد', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'کاربر مرتبط با لایسنس یافت نشد'
                    ], 404);
                }

                // بررسی دسترسی ادمین
                if (!$authUser->is_admin) {
                    Log::warning('کاربر دسترسی ادمین ندارد', [
                        'user_id' => $authUser->id,
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'شما دسترسی لازم برای انجام این عملیات را ندارید'
                    ], 403);
                }

            } catch (\Exception $e) {
                Log::error('خطا در احراز هویت توکن', [
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در احراز هویت توکن'
                ], 401);
            }

            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'کاربر یافت نشد'
                ], 404);
            }

            $permissions = $user->permissions()
                ->where('is_active', true)
                ->get()
                ->groupBy('group');

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'permissions' => $permissions
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get user permissions failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت دسترسی‌های کاربر',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
