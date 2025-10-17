<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    /**
     * تبدیل داده مدل به ساختار flat برای پلاگین
     */
    public function toPluginArray()
    {
        $arr = $this->toArray();
        // اگر invoice_settings وجود دارد، کلیدهای آن را به سطح بالا منتقل کن
        if (isset($arr['invoice_settings']) && is_array($arr['invoice_settings'])) {
            foreach ($arr['invoice_settings'] as $k => $v) {
                $arr[$k] = $v;
            }
            unset($arr['invoice_settings']);
        }
        return $arr;
    }

    /**
     * تبدیل داده flat پلاگین به ساختار دیتابیس (invoice_settings آرایه)
     */
    public static function fromPluginArray(array $data)
    {
        $invoiceKeys = [
            'cash_on_delivery',
            'credit_payment',
            'invoice_pending_type',
            'invoice_on_hold_type',
            'invoice_processing_type',
            'invoice_complete_type',
            'invoice_cancelled_type',
            'invoice_refunded_type',
            'invoice_failed_type'
        ];
        $db = $data;
        // اگر invoice_settings به صورت آرایه وجود داشت، همان را نگه دار
        if (isset($db['invoice_settings']) && is_array($db['invoice_settings'])) {
            // هیچ کاری نکن
        } else {
            $db['invoice_settings'] = [];
            foreach ($invoiceKeys as $key) {
                if (array_key_exists($key, $db)) {
                    $db['invoice_settings'][$key] = $db[$key];
                    unset($db[$key]);
                }
            }
        }
        return $db;
    }
    protected $fillable = [
        'license_id',
        'enable_price_update',
        'enable_stock_update',
        'enable_name_update',
        'enable_new_product',
        'enable_invoice',
        'enable_cart_sync',
        'enable_dynamic_warehouse_invoice',
        'payment_gateways',
        'invoice_settings',
        'rain_sale_price_unit',
        'woocommerce_price_unit',
        'shipping_cost_method',
        'shipping_product_unique_id',
        'default_warehouse_code'
    ];

    protected $casts = [
        'enable_price_update' => 'boolean',
        'enable_stock_update' => 'boolean',
        'enable_name_update' => 'boolean',
        'enable_new_product' => 'boolean',
        'enable_invoice' => 'boolean',
        'enable_cart_sync' => 'boolean',
        'enable_dynamic_warehouse_invoice'=> 'boolean',
        'payment_gateways' => 'array',
        'invoice_settings' => 'array'
    ];

    /**
     * رابطه با لایسنس
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
