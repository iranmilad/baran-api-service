<?php

namespace App\Traits;

trait PriceUnitConverter
{
    /**
     * تبدیل قیمت بین واحدهای ریال و تومان
     *
     * @param float $price قیمت اصلی
     * @param string $fromUnit واحد مبدا (rial/toman)
     * @param string $toUnit واحد مقصد (rial/toman)
     * @return float قیمت تبدیل شده
     */
    protected function convertPriceUnit(float $price, string $fromUnit, string $toUnit): float
    {
        // اگر واحدها یکسان باشند، نیازی به تبدیل نیست
        if ($fromUnit === $toUnit) {
            return $price;
        }

        // تبدیل از ریال به تومان
        if ($fromUnit === 'rial' && $toUnit === 'toman') {
            return $price / 10;
        }

        // تبدیل از تومان به ریال
        if ($fromUnit === 'toman' && $toUnit === 'rial') {
            return $price * 10;
        }

        return $price;
    }

    /**
     * محاسبه قیمت نهایی با اعمال تخفیف و افزایش قیمت
     *
     * @param float $basePrice قیمت پایه
     * @param float|null $discountPercentage درصد تخفیف
     * @param float|null $increasePercentage درصد افزایش قیمت
     * @return float قیمت نهایی
     */
    protected function calculateFinalPrice(float $basePrice, ?float $discountPercentage = null, ?float $increasePercentage = null): float
    {
        $price = $basePrice;

        // اعمال تخفیف
        if ($discountPercentage > 0) {
            $price = $price * (1 - ($discountPercentage / 100));
        }

        // اعمال افزایش قیمت
        if ($increasePercentage > 0) {
            $price = $price * (1 + ($increasePercentage / 100));
        }

        return round($price);
    }
}
