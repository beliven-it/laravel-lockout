<?php

namespace Beliven\Lockout\Events;

use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EntityUnlocked
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Model $model,
        public ModelLockout $modelLockout,
        public string $identifier,
        public object $requestData,
    ) {}
}
