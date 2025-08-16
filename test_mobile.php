<?php

// تست کردن تابع استاندارد کردن شماره موبایل

function standardizeMobileNumber($mobile)
{
    if (empty($mobile)) {
        return null;
    }

    // حذف همه کاراکترهای غیر عددی
    $mobile = preg_replace('/[^0-9]/', '', $mobile);

    // بررسی و تبدیل فرمت‌های مختلف
    if (strlen($mobile) == 10 && !str_starts_with($mobile, '0')) {
        // شماره 10 رقمی بدون 0 اول (مثل 9124100137)
        $mobile = '0' . $mobile;
    } elseif (strlen($mobile) == 13 && str_starts_with($mobile, '98')) {
        // شماره با کد کشور 98 (مثل 989124100137)
        $mobile = '0' . substr($mobile, 2);
    } elseif (strlen($mobile) == 14 && str_starts_with($mobile, '0098')) {
        // شماره با 0098 (مثل 00989124100137)
        $mobile = '0' . substr($mobile, 4);
    } elseif (strlen($mobile) == 12 && str_starts_with($mobile, '98')) {
        // شماره با +98 که + حذف شده (مثل 989124100137)
        $mobile = '0' . substr($mobile, 2);
    }

    // بررسی نهایی: باید 11 رقم باشد و با 09 شروع شود
    if (strlen($mobile) == 11 && str_starts_with($mobile, '09')) {
        return $mobile;
    }

    return null;
}

// تست‌های مختلف
$testNumbers = [
    '09124100137',           // استاندارد
    '+989124100137',         // با +98
    '00989124100137',        // با 0098
    '989124100137',          // با 98
    '9124100137',            // بدون 0 اول
    '0912-410-0137',         // با خط تیره
    '0912 410 0137',         // با فاصله
    '(0912) 410-0137',       // با پرانتز
];

echo "تست استاندارد کردن شماره موبایل:\n\n";

foreach ($testNumbers as $number) {
    $result = standardizeMobileNumber($number);
    echo "ورودی: {$number} -> خروجی: " . ($result ?: 'نامعتبر') . "\n";
}
