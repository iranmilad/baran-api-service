<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
}
