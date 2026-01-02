<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'setup';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting setup...');

        $this->call(CreateAdminUserCommand::class);
        $this->call(CreateBybitAccountCommand::class);

        $this->info('Setup completed!');
    }
}
