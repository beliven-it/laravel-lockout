<?php

namespace Beliven\Lockout\Events;

use Beliven\Lockout\Contracts\LockableModel;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EntityUnlocked
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * Accept either the LockableModel contract or a plain Eloquent Model for
     * backwards compatibility with tests/consumers that use raw Eloquent models.
     */
    public function __construct(
        public LockableModel|EloquentModel $model,
        public ModelLockout $modelLockout,
        public string $identifier,
        public object $requestData,
    ) {}
}
