<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateOkxAccountCommand extends Command
{
    protected $signature = 'create-okx-account 
                            {--force : Delete existing account before creating new one}
                            {--no-encrypt : Do not encrypt api_secret (for debugging only)}';

    protected $description = 'Create OKX exchange account from .env credentials';

    public function handle()
    {
        $user = User::query()
            ->where('email', config('app.admin.email'))
            ->first();

        if (!$user) {
            $this->error('User not found. Run: php artisan create-admin');
            return self::FAILURE;
        }

        $this->info('Creating OKX account...');
        $this->line('');

        // Проверяем наличие ключей в .env
        $apiKey = config('services.okx.key');
        $apiSecret = config('services.okx.secret');
        $passphrase = config('services.okx.passphrase');

        if (!$apiKey || !$apiSecret || !$passphrase) {
            $this->error('OKX API credentials not found in .env');
            $this->line('');
            $this->line('Required variables:');
            $this->line('  OKX_API_KEY - API key from OKX');
            $this->line('  OKX_API_SECRET - API secret from OKX');
            $this->line('  OKX_API_PASSPHRASE - Passphrase you set when creating API key');
            $this->line('');
            $this->line('Add them to .env file and run this command again.');
            return self::FAILURE;
        }

        // Если указан --force, удаляем старый аккаунт
        if ($this->option('force')) {
            $deleted = ExchangeAccount::where('exchange', 'okx')
                ->where('user_id', $user->id)
                ->delete();
            if ($deleted > 0) {
                $this->info("🗑️  Deleted {$deleted} existing account(s)");
                $this->line('');
            }
        }

        try {
            if ($this->option('no-encrypt')) {
                $this->warn('⚠️  WARNING: Creating account WITHOUT encryption (debugging only!)');
                ExchangeAccount::where('user_id', $user->id)
                    ->where('exchange', 'okx')
                    ->delete();
                $id = DB::table('exchange_accounts')->insertGetId([
                    'user_id' => $user->id,
                    'exchange' => 'okx',
                    'is_testnet' => false,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $account = ExchangeAccount::find($id);
            } else {
                $account = ExchangeAccount::query()
                    ->updateOrCreate([
                        'user_id' => $user->id,
                        'exchange' => 'okx',
                    ], [
                        'api_key' => $apiKey,
                        'api_secret' => $apiSecret,
                        'is_testnet' => false,
                    ]);
            }

            if ($account->wasRecentlyCreated) {
                $this->info("✅ OKX account created: #{$account->id}");
            } else {
                $this->info("🔄 OKX account updated: #{$account->id}");
            }

            // Примечание про passphrase
            $this->line('');
            $this->warn('⚠️  Note: OKX API requires passphrase, but we store it in config, not in DB');
            $this->line('   Make sure OKX_API_PASSPHRASE is set in .env');

            return self::SUCCESS;

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            $this->warn('⚠️  Cannot decrypt existing account. Deleting and recreating...');
            ExchangeAccount::where('user_id', $user->id)
                ->where('exchange', 'okx')
                ->delete();

            $account = ExchangeAccount::create([
                'user_id' => $user->id,
                'exchange' => 'okx',
                'is_testnet' => false,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ]);

            $this->info("✅ OKX account recreated: #{$account->id}");
            return self::SUCCESS;
        }
    }
}
