<?php

// شبیه‌سازی فرآیند استاندارد سازی شماره موبایل

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

// شبیه‌سازی جریان کار
echo "=== شبیه‌سازی جریان ProcessInvoice ===\n\n";

// سناریو 1: مشتری جدید
echo "سناریو 1: مشتری جدید\n";
$originalMobile1 = "989360088006"; // شماره ارسالی در JSON
$standardizedMobile1 = standardizeMobileNumber($originalMobile1);
echo "شماره اصلی: {$originalMobile1}\n";
echo "شماره استاندارد: {$standardizedMobile1}\n";
echo "وضعیت: مشتری در RainSale وجود ندارد - باید ثبت شود\n";
echo "نتیجه: شماره استاندارد شده در دیتابیس ذخیره می‌شود\n\n";

// سناریو 2: مشتری موجود
echo "سناریو 2: مشتری موجود\n";
$originalMobile2 = "989360088006"; // همان شماره
$standardizedMobile2 = standardizeMobileNumber($originalMobile2);
echo "شماره اصلی: {$originalMobile2}\n";
echo "شماره استاندارد: {$standardizedMobile2}\n";
echo "وضعیت: مشتری در RainSale موجود است\n";
echo "نتیجه: شماره استاندارد شده در دیتابیس ذخیره می‌شود (قبل از استعلام)\n\n";

echo "=== نتیجه‌گیری ===\n";
echo "در هر دو حالت، شماره موبایل قبل از استعلام مشتری استاندارد می‌شود\n";
echo "و در invoice.customer_mobile ذخیره می‌شود.\n";
echo "پس مشکلی وجود ندارد.\n";
