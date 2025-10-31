<?php

namespace Beliven\Lockout\Commands;

use Illuminate\Console\Command;

class LockoutCommand extends Command
{
    public $signature = 'laravel-lockout';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
