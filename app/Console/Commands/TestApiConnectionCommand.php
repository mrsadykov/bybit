<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Services\Exchanges\Bybit\BybitService;
use Illuminate\Console\Command;

class TestApiConnectionCommand extends Command
{
    protected $signature = 'api:test {--account= : Account ID}';
    protected $description = 'Test API connection and show detailed diagnostics';

    public function handle(): int
    {
        $accountId = $this->option('account');
        
        if ($accountId) {
            $account = ExchangeAccount::find($accountId);
        } else {
            $account = ExchangeAccount::where('exchange', 'bybit')
                ->where('is_testnet', true)
                ->first();
        }

        if (! $account) {
            $this->error('Account not found');
            return self::FAILURE;
        }

        $this->info('Testing API connection...');
        $this->line('Account ID: ' . $account->id);
        $this->line('Testnet: ' . ($account->is_testnet ? 'Yes' : 'No'));
        $this->line('Base URL: ' . ($account->is_testnet ? 'https://api-testnet.bybit.com' : 'https://api.bybit.com'));
        $this->line('');

        // Проверка ключей
        $this->info('📋 Checking API keys...');
        $apiKey = $account->api_key;
        $this->line('API Key: ' . substr($apiKey, 0, 10) . '...' . substr($apiKey, -5));
        $this->line('API Key length: ' . strlen($apiKey));
        
        try {
            $apiSecret = $account->api_secret;
            $this->line('API Secret: ✅ Decrypted successfully');
            $this->line('API Secret length: ' . strlen($apiSecret));
        } catch (\Exception $e) {
            $this->error('API Secret: ❌ Decryption failed - ' . $e->getMessage());
            $this->line('');
            $this->warn('This means APP_KEY changed. Run: php artisan create-bybit-account');
            return self::FAILURE;
        }

        $this->line('');

        // Тест публичного запроса
        $this->info('🔗 Testing public API (no auth required)...');
        try {
            $bybit = new BybitService($account);
            $price = $bybit->getPrice('BTCUSDT');
            $this->info("✅ Public API works! BTC Price: {$price} USDT");
        } catch (\Throwable $e) {
            $this->error('❌ Public API failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('');

        // Тест приватного запроса
        $this->info('🔐 Testing private API (requires auth)...');
        try {
            $balance = $bybit->getBalance('USDT');
            $this->info("✅ Private API works! USDT Balance: {$balance}");
            
            $this->line('');
            $this->info('📊 All balances:');
            $balances = $bybit->getAllBalances();
            if (empty($balances)) {
                $this->warn('No balances found');
            } else {
                foreach ($balances as $coin => $amount) {
                    $this->line("  {$coin}: {$amount}");
                }
            }
            
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Private API failed: ' . $e->getMessage());
            $this->line('');
            $this->warn('Possible issues:');
            $this->line('1. API secret is wrong - check that secret is correct in .env');
            $this->line('2. API key doesn\'t have Read permission - check on bybit.com');
            $this->line('3. API keys from wrong environment (testnet vs production)');
            $this->line('4. IP whitelist blocking - disable IP whitelist for testing');
            $this->line('5. API key expired or revoked - create new key');
            $this->line('');
            $this->info('💡 Next steps:');
            $this->line('1. Go to bybit.com → API Management');
            $this->line('2. Check that your API key has "Read" permission');
            $this->line('3. Check IP whitelist (disable for testing)');
            $this->line('4. If still not working, create new API key');
            $this->line('5. Update .env and run: php artisan create-bybit-account');
            return self::FAILURE;
        }
    }
}
