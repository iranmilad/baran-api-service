<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'fullname',
        'website',
        'serial',
        'template',
        'service_capacity',
        'buy_start_date',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'postal_code',
        'company_name',
        'registration_number',
        'national_id',
        'tax_number',
    ];

    // تعریف روابط (در صورت وجود)
}
