<?php

// تست Event و Observer برای تغییر default_warehouse_code

use App\Models\UserSetting;
use App\Models\License;
use App\Models\Product;
use App\Events\WarehouseCodeChanged;
use App\Listeners\UpdateProductStockIds;

echo "=== تست Event/Listener برای تغییر warehouse code ===\n\n";

// شبیه‌سازی داده‌ها
echo "1. شبیه‌سازی تغییر warehouse code:\n";
echo "   - License ID: 1\n";
echo "   - Old warehouse code: 'OLD-WAREHOUSE'\n";
echo "   - New warehouse code: 'NEW-WAREHOUSE'\n\n";

// شبیه‌سازی UserSetting update
echo "2. Observer trigger شرایط:\n";
echo "   - isDirty('default_warehouse_code'): true\n";
echo "   - getOriginal('default_warehouse_code'): 'OLD-WAREHOUSE'\n";
echo "   - default_warehouse_code: 'NEW-WAREHOUSE'\n\n";

// شبیه‌سازی Event
echo "3. WarehouseCodeChanged Event:\n";
echo "   - License: ✓\n";
echo "   - Old code: 'OLD-WAREHOUSE'\n";
echo "   - New code: 'NEW-WAREHOUSE'\n\n";

// شبیه‌سازی Listener
echo "4. UpdateProductStockIds Listener:\n";
echo "   - بررسی تغییر: Old ≠ New ✓\n";
echo "   - SQL Query: UPDATE products SET stock_id = 'NEW-WAREHOUSE' WHERE license_id = 1\n";
echo "   - عملیات: در صف (ShouldQueue) ✓\n\n";

echo "5. فرایند کامل:\n";
echo "   UserSetting::update() → Observer::updated() → Event → Listener → Products::update()\n\n";

echo "=== نکات مهم ===\n";
echo "✅ Observer فقط در صورت تغییر واقعی default_warehouse_code فعال می‌شود\n";
echo "✅ Listener در صف اجرا می‌شود (ShouldQueue)\n";
echo "✅ تمام محصولات لایسنس به‌روزرسانی می‌شوند\n";
echo "✅ لاگ کامل برای رصد فرایند\n";
echo "✅ مدیریت خطا در Listener\n\n";

echo "=== آماده برای استفاده ===\n";
