<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create-admin';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        User::firstOrCreate([
            'email' => config('app.admin.email'),
        ], [
            'name' => config('app.admin.name'),
            'password' => Hash::make(config('app.admin.password')),
        ]);
    }
}
