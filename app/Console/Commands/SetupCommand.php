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

        $adminResult = $this->call(CreateAdminUserCommand::class);
        $accountResult = $this->call(CreateBybitAccountCommand::class);

        if ($adminResult === self::SUCCESS && $accountResult === self::SUCCESS) {
            $this->info('Setup completed successfully!');
            return self::SUCCESS;
        }

        $this->error('Setup completed with errors');
        return self::FAILURE;
    }
}
