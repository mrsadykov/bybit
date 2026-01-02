<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Models\User;
use Illuminate\Console\Command;

class CreateBybitAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create-bybit-account';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::query()
            ->where('email', config('app.admin.email'))
            ->first();

        if (!$user) {
            $this->error('User not found');
            return;
        }

        ExchangeAccount::query()
            ->updateOrCreate([
                'user_id' => $user->id,
                'exchange' => 'bybit'
            ], [
                'api_key' => config('services.bybit.key'),
                'api_secret' => config('services.bybit.secret'),
            ]);
    }
}
