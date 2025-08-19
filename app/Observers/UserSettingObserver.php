<?php

namespace App\Observers;

use App\Events\WarehouseCodeChanged;
use App\Models\UserSetting;
use Illuminate\Support\Facades\Log;

class UserSettingObserver
{
    /**
     * Handle the UserSetting "created" event.
     */
    public function created(UserSetting $userSetting): void
    {
        // اگر یک default_warehouse_code در ایجاد تنظیم شده، آن را اعمال کن
        if (!empty($userSetting->default_warehouse_code)) {
            Log::info('default_warehouse_code جدید در ایجاد تنظیمات', [
                'license_id' => $userSetting->license_id,
                'new_warehouse_code' => $userSetting->default_warehouse_code
            ]);

            event(new WarehouseCodeChanged(
                $userSetting->license,
                null, // مقدار قبلی در ایجاد وجود ندارد
                $userSetting->default_warehouse_code
            ));
        }
    }

    /**
     * Handle the UserSetting "updated" event.
     */
    public function updated(UserSetting $userSetting): void
    {
        // بررسی تغییر default_warehouse_code
        if ($userSetting->isDirty('default_warehouse_code')) {
            $oldWarehouseCode = $userSetting->getOriginal('default_warehouse_code');
            $newWarehouseCode = $userSetting->default_warehouse_code;

            Log::info('تشخیص تغییر default_warehouse_code', [
                'license_id' => $userSetting->license_id,
                'old_warehouse_code' => $oldWarehouseCode,
                'new_warehouse_code' => $newWarehouseCode
            ]);

            // فقط اگر واقعاً تغییر کرده باشد
            if ($oldWarehouseCode !== $newWarehouseCode) {
                event(new WarehouseCodeChanged(
                    $userSetting->license,
                    $oldWarehouseCode,
                    $newWarehouseCode
                ));
            }
        }
    }

    /**
     * Handle the UserSetting "deleted" event.
     */
    public function deleted(UserSetting $userSetting): void
    {
        //
    }

    /**
     * Handle the UserSetting "restored" event.
     */
    public function restored(UserSetting $userSetting): void
    {
        //
    }

    /**
     * Handle the UserSetting "force deleted" event.
     */
    public function forceDeleted(UserSetting $userSetting): void
    {
        //
    }
}
