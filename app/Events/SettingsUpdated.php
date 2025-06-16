<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\License;
use App\Models\UserSetting;

class SettingsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $license;
    public $settings;

    public function __construct(License $license, UserSetting $settings)
    {
        $this->license = $license;
        $this->settings = $settings;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('settings.' . $this->license->id);
    }
}
