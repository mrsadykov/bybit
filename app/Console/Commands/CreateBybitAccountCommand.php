<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateBybitAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create-bybit-account 
                            {--force : Delete existing accounts before creating new ones}
                            {--no-encrypt : Do not encrypt api_secret (for debugging only)}';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::query()
            ->where('email', config('app.admin.email'))
            ->first();

        if (!$user) {
            $this->error('User not found. Run: php artisan create-admin');
            return self::FAILURE;
        }

        $this->info('Creating Bybit accounts...');
        $this->line('');

        // Если указан --force, удаляем старые аккаунты
        if ($this->option('force')) {
            $deleted = ExchangeAccount::where('exchange', 'bybit')
                ->where('user_id', $user->id)
                ->delete();
            if ($deleted > 0) {
                $this->info("🗑️  Deleted {$deleted} existing account(s)");
                $this->line('');
            }
        }

        $created = 0;
        $updated = 0;

        // 1. Production аккаунт (bybit.com)
        $productionKey = config('services.bybit.key');
        $productionSecret = config('services.bybit.secret');

        if ($productionKey && $productionSecret) {
            // Если --no-encrypt, используем прямую запись в БД без модели
            if ($this->option('no-encrypt')) {
                $this->warn('⚠️  WARNING: Creating account WITHOUT encryption (debugging only!)');
                
                // Удаляем старый аккаунт если есть
                ExchangeAccount::where('user_id', $user->id)
                    ->where('exchange', 'bybit')
                    ->where('is_testnet', false)
                    ->delete();
                
                // Вставляем напрямую в БД без шифрования
                $id = DB::table('exchange_accounts')->insertGetId([
                    'user_id' => $user->id,
                    'exchange' => 'bybit',
                    'is_testnet' => false,
                    'api_key' => $productionKey,
                    'api_secret' => $productionSecret, // Без шифрования!
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $productionAccount = ExchangeAccount::find($id);
                $productionAccount->wasRecentlyCreated = true; // Для корректного отображения
            } else {
                try {
                    $productionAccount = ExchangeAccount::query()
                        ->updateOrCreate([
                            'user_id' => $user->id,
                            'exchange' => 'bybit',
                            'is_testnet' => false,
                        ], [
                            'api_key' => $productionKey,
                            'api_secret' => $productionSecret,
                        ]);
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    // Если не можем расшифровать старые данные, удаляем и создаем заново
                    $this->warn('⚠️  Cannot decrypt existing production account. Deleting and recreating...');
                    ExchangeAccount::where('user_id', $user->id)
                        ->where('exchange', 'bybit')
                        ->where('is_testnet', false)
                        ->delete();
                    
                    $productionAccount = ExchangeAccount::create([
                        'user_id' => $user->id,
                        'exchange' => 'bybit',
                        'is_testnet' => false,
                        'api_key' => $productionKey,
                        'api_secret' => $productionSecret,
                    ]);
                }
            }

            if ($productionAccount->wasRecentlyCreated) {
                $created++;
                $this->info("✅ Production account created: #{$productionAccount->id}");
            } else {
                $updated++;
                $this->info("🔄 Production account updated: #{$productionAccount->id}");
            }
        } else {
            $this->warn('⚠️  Production API keys not found in .env (BYBIT_API_KEY, BYBIT_API_SECRET)');
        }

        // 2. Testnet аккаунт (testnet.bybit.com)
        $testnetKey = config('services.bybit.testnet_key');
        $testnetSecret = config('services.bybit.testnet_secret');

        if ($testnetKey && $testnetSecret) {
            // Если --no-encrypt, используем прямую запись в БД без модели
            if ($this->option('no-encrypt')) {
                $this->warn('⚠️  WARNING: Creating account WITHOUT encryption (debugging only!)');
                
                // Удаляем старый аккаунт если есть
                ExchangeAccount::where('user_id', $user->id)
                    ->where('exchange', 'bybit')
                    ->where('is_testnet', true)
                    ->delete();
                
                // Вставляем напрямую в БД без шифрования
                $id = DB::table('exchange_accounts')->insertGetId([
                    'user_id' => $user->id,
                    'exchange' => 'bybit',
                    'is_testnet' => true,
                    'api_key' => $testnetKey,
                    'api_secret' => $testnetSecret, // Без шифрования!
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                $testnetAccount = ExchangeAccount::find($id);
                $testnetAccount->wasRecentlyCreated = true; // Для корректного отображения
            } else {
                try {
                    $testnetAccount = ExchangeAccount::query()
                        ->updateOrCreate([
                            'user_id' => $user->id,
                            'exchange' => 'bybit',
                            'is_testnet' => true,
                        ], [
                            'api_key' => $testnetKey,
                            'api_secret' => $testnetSecret,
                        ]);
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    // Если не можем расшифровать старые данные, удаляем и создаем заново
                    $this->warn('⚠️  Cannot decrypt existing testnet account. Deleting and recreating...');
                    ExchangeAccount::where('user_id', $user->id)
                        ->where('exchange', 'bybit')
                        ->where('is_testnet', true)
                        ->delete();
                    
                    $testnetAccount = ExchangeAccount::create([
                        'user_id' => $user->id,
                        'exchange' => 'bybit',
                        'is_testnet' => true,
                        'api_key' => $testnetKey,
                        'api_secret' => $testnetSecret,
                    ]);
                }
            }

            if ($testnetAccount->wasRecentlyCreated) {
                $created++;
                $this->info("✅ Testnet account created: #{$testnetAccount->id}");
            } else {
                $updated++;
                $this->info("🔄 Testnet account updated: #{$testnetAccount->id}");
            }
        } else {
            $this->warn('⚠️  Testnet API keys not found in .env (BYBIT_TESTNET_API_KEY, BYBIT_TESTNET_API_SECRET)');
        }

        $this->line('');
        
        if ($created > 0 || $updated > 0) {
            $this->info("Summary: {$created} created, {$updated} updated");
            return self::SUCCESS;
        } else {
            $this->error('No accounts were created. Check your .env file.');
            $this->line('');
            $this->line('Required variables:');
            $this->line('  BYBIT_API_KEY - Production API key');
            $this->line('  BYBIT_API_SECRET - Production API secret');
            $this->line('  BYBIT_TESTNET_API_KEY - Testnet API key');
            $this->line('  BYBIT_TESTNET_API_SECRET - Testnet API secret');
            return self::FAILURE;
        }
    }
}
