<?php

namespace App\Events;

use App\Models\License;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WarehouseCodeChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $license;
    public $oldWarehouseCode;
    public $newWarehouseCode;

    /**
     * Create a new event instance.
     */
    public function __construct(License $license, ?string $oldWarehouseCode, ?string $newWarehouseCode)
    {
        $this->license = $license;
        $this->oldWarehouseCode = $oldWarehouseCode;
        $this->newWarehouseCode = $newWarehouseCode;
    }
}
