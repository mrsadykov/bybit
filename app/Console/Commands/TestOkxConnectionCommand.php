<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Services\Exchanges\OKX\OKXService;
use Illuminate\Console\Command;

class TestOkxConnectionCommand extends Command
{
    protected $signature = 'okx:test {--account= : Account ID to test}';
    protected $description = 'Test OKX API connection';

    public function handle(): int
    {
        $accountId = $this->option('account');

        if (!$accountId) {
            $accounts = ExchangeAccount::where('exchange', 'okx')->get();
            if ($accounts->isEmpty()) {
                $this->error('No OKX accounts found. Run: php artisan create-okx-account');
                return self::FAILURE;
            }
            $account = $accounts->first();
            $this->info("Using account #{$account->id} (use --account=ID to specify)");
        } else {
            $account = ExchangeAccount::where('exchange', 'okx')
                ->where('id', $accountId)
                ->first();
            if (!$account) {
                $this->error("OKX account #{$accountId} not found");
                $this->showAvailableAccounts();
                return self::FAILURE;
            }
        }

        $this->line(str_repeat('-', 40));
        $this->info("Testing OKX API connection for Account #{$account->id}");
        $this->line('');

        // Проверка passphrase
        $passphrase = config('services.okx.passphrase');
        if (empty($passphrase)) {
            $this->error('OKX_API_PASSPHRASE is not set in .env!');
            $this->line('');
            $this->line('OKX requires passphrase for API requests.');
            $this->line('Add OKX_API_PASSPHRASE to your .env file.');
            return self::FAILURE;
        }

        try {
            $okx = new OKXService($account);

            // Тест 1: Получение цены (публичный endpoint)
            $this->info('1. Testing public API (getting BTC price)...');
            try {
                $price = $okx->getPrice('BTC-USDT');
                $this->info("   ✅ Public API works! BTC price: {$price} USDT");
            } catch (\Throwable $e) {
                $this->error("   ❌ Public API failed: " . $e->getMessage());
                return self::FAILURE;
            }

            $this->line('');

            // Тест 2: Получение баланса (приватный endpoint)
            $this->info('2. Testing private API (getting balance)...');
            try {
                $balance = $okx->getBalance('USDT');
                $this->info("   ✅ Private API works! USDT balance: {$balance}");
            } catch (\Throwable $e) {
                $this->error("   ❌ Private API failed: " . $e->getMessage());
                $this->line('');
                $this->line('Possible reasons:');
                $this->line('1. Wrong API key or secret');
                $this->line('2. Wrong passphrase');
                $this->line('3. API key doesn\'t have Read permission');
                $this->line('4. IP whitelist enabled (disable or add your IP)');
                return self::FAILURE;
            }

            $this->line('');
            $this->info('✅ All tests passed! OKX API connection is working.');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    protected function showAvailableAccounts(): void
    {
        $accounts = ExchangeAccount::where('exchange', 'okx')->get();
        if ($accounts->isEmpty()) {
            $this->line('  No OKX accounts found. Run: php artisan create-okx-account');
            return;
        }

        $this->line('Available OKX accounts:');
        foreach ($accounts as $account) {
            $this->line("  ID: {$account->id}");
        }
    }
}
