<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_name',
        'ip_address',
        'dns1',
        'dns2',
        'ram',
        'cpu_cores',
        'storage',
        'os',
        'location',
        'admin_user',
        'admin_password',
        'is_active',      // وضعیت فعال/غیرفعال
        'is_full',        // وضعیت پر/خالی
    ];

    public function isActive()
    {
        return $this->is_active; // تغییر به وضعیت فعال
    }

    public function isFull()
    {
        return $this->is_full; // وضعیت پر
    }


}
