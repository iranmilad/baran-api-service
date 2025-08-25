<?php

namespace App\Http\Controllers;

use App\Models\LicenseWarehouseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class LicenseWarehouseCategoryController extends Controller
{
    /**
     * دریافت لیست کتگوری‌های انبار
     */
    public function index()
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            $categories = LicenseWarehouseCategory::where('license_id', $license->id)->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت کتگوری‌ها'
            ], 500);
        }
    }

    /**
     * ایجاد کتگوری جدید
     */
    public function store(Request $request)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'category_name' => 'required|string|max:255',
                'warehouse_codes' => 'required|array|min:1',
                'warehouse_codes.*' => 'required|string|uuid' // stockID ها باید UUID باشند
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // بررسی تکراری نبودن نام کتگوری
            $exists = LicenseWarehouseCategory::where('license_id', $license->id)
                ->where('category_name', $request->category_name)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'کتگوری با این نام قبلاً وجود دارد'
                ], 409);
            }

            $category = LicenseWarehouseCategory::create([
                'license_id' => $license->id,
                'category_name' => $request->category_name,
                'warehouse_codes' => $request->warehouse_codes
            ]);

            return response()->json([
                'success' => true,
                'message' => 'کتگوری با موفقیت ایجاد شد',
                'data' => $category
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد کتگوری'
            ], 500);
        }
    }

    /**
     * به‌روزرسانی کتگوری
     */
    public function update(Request $request, $id)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            $category = LicenseWarehouseCategory::where('license_id', $license->id)
                ->where('id', $id)
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'کتگوری یافت نشد'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'category_name' => 'sometimes|required|string|max:255',
                'warehouse_codes' => 'sometimes|required|array|min:1',
                'warehouse_codes.*' => 'required|string|uuid' // stockID ها باید UUID باشند
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // بررسی تکراری نبودن نام جدید
            if ($request->has('category_name') && $request->category_name !== $category->category_name) {
                $exists = LicenseWarehouseCategory::where('license_id', $license->id)
                    ->where('category_name', $request->category_name)
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'کتگوری با این نام قبلاً وجود دارد'
                    ], 409);
                }
            }

            $category->update($request->only(['category_name', 'warehouse_codes']));

            return response()->json([
                'success' => true,
                'message' => 'کتگوری با موفقیت به‌روزرسانی شد',
                'data' => $category->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در به‌روزرسانی کتگوری'
            ], 500);
        }
    }

    /**
     * حذف کتگوری
     */
    public function destroy($id)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            $category = LicenseWarehouseCategory::where('license_id', $license->id)
                ->where('id', $id)
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'کتگوری یافت نشد'
                ], 404);
            }

            $category->delete();

            return response()->json([
                'success' => true,
                'message' => 'کتگوری با موفقیت حذف شد'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در حذف کتگوری'
            ], 500);
        }
    }
}
