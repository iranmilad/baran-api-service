<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\NotificationDismissal;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    public function index()
    {

        $notifications = Notification::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>', now());
            })
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'type' => $notification->type,
                    'expiry_date' => $notification->expiry_date ? $notification->expiry_date->format('Y-m-d H:i:s') : null
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications
            ]
        ]);
    }

    public function dismiss(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notification_id' => 'required|exists:notifications,id',
            'license_key' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        NotificationDismissal::create([
            'notification_id' => $request->notification_id,
            'license_key' => $request->license_key
        ]);

        return response()->json([
            'success' => true,
            'message' => 'اطلاعیه با موفقیت بسته شد'
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:info,success,warning,error',
            'expiry_date' => 'nullable|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        $notification = Notification::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'اطلاعیه با موفقیت ایجاد شد',
            'data' => $notification
        ]);
    }

    public function update(Request $request, Notification $notification)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:info,success,warning,error',
            'expiry_date' => 'nullable|date',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        $notification->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'اطلاعیه با موفقیت به‌روزرسانی شد',
            'data' => $notification
        ]);
    }

    public function destroy(Notification $notification)
    {
        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'اطلاعیه با موفقیت حذف شد'
        ]);
    }
}
