<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait PaymentGatewayAccountHandler
{
    /**
     * دریافت شماره حساب درگاه پرداخت بر اساس روش پرداخت
     *
     * @param string $paymentMethod روش پرداخت از WooCommerce (مثل cod, zarinpal, parsian)
     * @param array $paymentGatewayAccounts تنظیمات حساب‌های درگاه پرداخت
     * @param string $context شناسه منحصر به فرد برای لاگ (مثل invoice_id)
     * @return string|null شماره حساب یا null در صورت عدم وجود
     */
    protected function getPaymentGatewayAccount($paymentMethod, $paymentGatewayAccounts, $context = 'unknown')
    {
        try {
            // بررسی وجود روش پرداخت و تنظیمات
            if (empty($paymentMethod)) {
                Log::info('روش پرداخت مشخص نشده', [
                    'context' => $context,
                    'payment_method' => $paymentMethod
                ]);
                return null;
            }

            if (empty($paymentGatewayAccounts) || !is_array($paymentGatewayAccounts)) {
                Log::info('تنظیمات درگاه‌های پرداخت موجود نیست', [
                    'context' => $context,
                    'payment_method' => $paymentMethod,
                    'has_accounts' => !empty($paymentGatewayAccounts),
                    'accounts_type' => gettype($paymentGatewayAccounts)
                ]);
                return null;
            }

            // نرمال‌سازی نام روش پرداخت (حذف کاراکترهای اضافی و تبدیل به حروف کوچک)
            $normalizedPaymentMethod = strtolower(trim($paymentMethod));

            // بررسی وجود شماره حساب برای روش پرداخت
            if (!isset($paymentGatewayAccounts[$normalizedPaymentMethod])) {
                Log::info('شماره حساب برای روش پرداخت یافت نشد', [
                    'context' => $context,
                    'payment_method' => $paymentMethod,
                    'normalized_method' => $normalizedPaymentMethod,
                    'available_methods' => array_keys($paymentGatewayAccounts)
                ]);
                return null;
            }

            $accountNumber = $paymentGatewayAccounts[$normalizedPaymentMethod];

            // بررسی اینکه شماره حساب خالی نباشد
            if (empty($accountNumber) || trim($accountNumber) === '') {
                Log::info('شماره حساب برای روش پرداخت خالی است', [
                    'context' => $context,
                    'payment_method' => $paymentMethod,
                    'normalized_method' => $normalizedPaymentMethod,
                    'account_number' => $accountNumber
                ]);
                return null;
            }

            Log::info('شماره حساب درگاه پرداخت یافت شد', [
                'context' => $context,
                'payment_method' => $paymentMethod,
                'normalized_method' => $normalizedPaymentMethod,
                'account_number' => $accountNumber
            ]);

            return trim($accountNumber);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت شماره حساب درگاه پرداخت', [
                'context' => $context,
                'payment_method' => $paymentMethod,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * تطبیق روش پرداخت WooCommerce با شناسه‌های متداول درگاه‌های پرداخت ایرانی
     *
     * @param string $wooPaymentMethod روش پرداخت از WooCommerce
     * @return array آرایه‌ای از احتمالات نام درگاه پرداخت
     */
    protected function mapWooCommercePaymentMethod($wooPaymentMethod)
    {
        // نرمال‌سازی نام روش پرداخت
        $normalizedMethod = strtolower(trim($wooPaymentMethod));

        // نقشه‌برداری روش‌های پرداخت WooCommerce به شناسه‌های درگاه
        $paymentMethodMap = [
            'cod' => ['cod', 'cash_on_delivery', 'cash'],
            'zarinpal' => ['zarinpal', 'zarin_pal', 'zarin'],
            'parsian' => ['parsian', 'parsian_bank'],
            'mellat' => ['mellat', 'behpardakht', 'bpm'],
            'melli' => ['melli', 'sadad'],
            'pasargad' => ['pasargad', 'pep'],
            'saman' => ['saman', 'sep'],
            'irankish' => ['irankish', 'ikp'],
            'payping' => ['payping', 'pay_ping'],
            'idpay' => ['idpay', 'id_pay'],
            'nextpay' => ['nextpay', 'next_pay'],
            'zibal' => ['zibal'],
            'rayanpay' => ['rayanpay', 'rayan_pay'],
            'vandar' => ['vandar'],
        ];

        // جستجو برای یافتن تطبیق
        foreach ($paymentMethodMap as $gatewayKey => $variations) {
            if (in_array($normalizedMethod, $variations)) {
                return [$gatewayKey]; // بازگشت کلید اصلی
            }
        }

        // اگر تطبیق مستقیمی یافت نشد، خود روش پرداخت را برگردان
        return [$normalizedMethod];
    }

    /**
     * دریافت شماره حساب با تلاش برای تطبیق هوشمند روش پرداخت
     *
     * @param string $paymentMethod روش پرداخت از WooCommerce
     * @param array $paymentGatewayAccounts تنظیمات حساب‌های درگاه پرداخت
     * @param string $context شناسه منحصر به فرد برای لاگ
     * @return string|null شماره حساب یا null در صورت عدم وجود
     */
    protected function getPaymentGatewayAccountWithMapping($paymentMethod, $paymentGatewayAccounts, $context = 'unknown')
    {
        // ابتدا تلاش مستقیم
        $accountNumber = $this->getPaymentGatewayAccount($paymentMethod, $paymentGatewayAccounts, $context);
        if ($accountNumber) {
            return $accountNumber;
        }

        // تلاش با تطبیق هوشمند
        $mappedMethods = $this->mapWooCommercePaymentMethod($paymentMethod);

        foreach ($mappedMethods as $mappedMethod) {
            $accountNumber = $this->getPaymentGatewayAccount($mappedMethod, $paymentGatewayAccounts, $context);
            if ($accountNumber) {
                Log::info('شماره حساب با تطبیق هوشمند یافت شد', [
                    'context' => $context,
                    'original_method' => $paymentMethod,
                    'mapped_method' => $mappedMethod,
                    'account_number' => $accountNumber
                ]);
                return $accountNumber;
            }
        }

        Log::info('شماره حساب با هیچ روشی یافت نشد', [
            'context' => $context,
            'payment_method' => $paymentMethod,
            'tried_methods' => $mappedMethods,
            'available_accounts' => array_keys($paymentGatewayAccounts ?? [])
        ]);

        return null;
    }

    /**
     * اضافه کردن شماره حساب به آرایه پرداخت در صورت وجود
     *
     * @param array $paymentData آرایه پرداخت موجود
     * @param string $paymentMethod روش پرداخت
     * @param array $paymentGatewayAccounts تنظیمات حساب‌های درگاه پرداخت
     * @param string $context شناسه منحصر به فرد برای لاگ
     * @return array آرایه پرداخت با شماره حساب (در صورت وجود)
     */
    protected function addAccountNumberToPayment($paymentData, $paymentMethod, $paymentGatewayAccounts, $context = 'unknown')
    {
        $accountNumber = $this->getPaymentGatewayAccountWithMapping($paymentMethod, $paymentGatewayAccounts, $context);

        if ($accountNumber) {
            $paymentData['AccountNumber'] = $accountNumber;

            Log::info('شماره حساب به پرداخت اضافه شد', [
                'context' => $context,
                'payment_method' => $paymentMethod,
                'account_number' => $accountNumber
            ]);
        } else {
            Log::info('شماره حساب برای پرداخت یافت نشد - پارامتر AccountNumber اضافه نخواهد شد', [
                'context' => $context,
                'payment_method' => $paymentMethod
            ]);
        }

        return $paymentData;
    }
}
