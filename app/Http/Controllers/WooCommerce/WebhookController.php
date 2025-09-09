<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Jobs\WooCommerce\ProcessProductChanges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;

class WebhookController extends Controller
{
    public function handleProductChanges(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'changes' => 'required|array',
            'changes.*.product' => 'required|array',
            'changes.*.product.barcode' => 'required|string',
            'changes.*.product.name' => 'required|string',
            'changes.*.product.price' => 'required|numeric',
            'changes.*.product.stock' => 'required|integer',
            'changes.*.product.attributes' => 'nullable|array',
            'changes.*.product.attributes.*.barcode' => 'required|string',
            'changes.*.product.attributes.*.name' => 'required|string',
            'changes.*.product.attributes.*.price' => 'required|numeric',
            'changes.*.product.attributes.*.stock' => 'required|integer',
            'changes.*.change_type' => 'required|in:new,update',
            'changes.*.changed_at' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return Response::json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // ارسال تغییرات به صف
            ProcessProductChanges::dispatch($request->changes);

            return Response::json([
                'success' => true,
                'message' => 'تغییرات با موفقیت در صف قرار گرفتند'
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش webhook: ' . $e->getMessage());

            return Response::json([
                'success' => false,
                'message' => 'خطا در پردازش تغییرات',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
