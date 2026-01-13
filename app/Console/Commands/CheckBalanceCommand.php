<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Services\Exchanges\Bybit\BybitService;
use Illuminate\Console\Command;

class CheckBalanceCommand extends Command
{
    protected $signature = 'balance:check 
                            {coin=USDT : Coin to check (USDT, BTC, etc.)}
                            {--account= : Account ID to check (optional)}
                            {--testnet : Use testnet account}
                            {--production : Use production account}';
    protected $description = 'Check balance on Bybit account';

    public function handle(): int
    {
        $coin = strtoupper($this->argument('coin'));

        // Определяем какой аккаунт использовать
        $account = $this->getAccount();

        if (! $account) {
            $this->error('Exchange account not found.');
            $this->line('');
            $this->line('Available accounts:');
            $this->showAvailableAccounts();
            $this->line('');
            $this->line('Usage:');
            $this->line('  php artisan balance:check --account=2');
            $this->line('  php artisan balance:check --testnet');
            $this->line('  php artisan balance:check --production');
            return self::FAILURE;
        }

        $this->info('Checking balance...');
        $this->line('Account ID: ' . $account->id);
        $this->line('Exchange: ' . $account->exchange);
        $this->line('Testnet: ' . ($account->is_testnet ? 'Yes' : 'No'));
        $this->line('');

        try {
            $bybit = new BybitService($account);

            // Проверка конкретной монеты
            $balance = $bybit->getBalance($coin);
            $this->info("💰 {$coin} Balance: {$balance}");

            $this->line('');
            $this->info('📊 All balances:');
            
            $allBalances = $bybit->getAllBalances();
            
            if (empty($allBalances)) {
                $this->warn('No balances found');
            } else {
                foreach ($allBalances as $coinName => $amount) {
                    if ($amount > 0) {
                        $this->line("  {$coinName}: {$amount}");
                    }
                }
            }

            // Проверка цены для теста подключения
            $this->line('');
            $this->info('🔗 Testing connection...');
            $price = $bybit->getPrice('BTCUSDT');
            $this->info("✅ Connection OK. BTC Price: {$price} USDT");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->error('Error: ' . $errorMessage);
            $this->line('');
            
            // Более детальные подсказки в зависимости от ошибки
            if (str_contains($errorMessage, 'encryption key')) {
                $this->warn('Application encryption key not set.');
                $this->line('Run: php artisan key:generate');
            } elseif (str_contains($errorMessage, 'payload is invalid') || str_contains($errorMessage, 'invalid signature')) {
                $this->warn('Possible causes:');
                $this->line('1. Wrong API secret key');
                $this->line('2. API keys don\'t have Read permission');
                $this->line('3. Keys from production instead of testnet (or vice versa)');
                $this->line('4. Time synchronization issue');
            } elseif (str_contains($errorMessage, 'retCode')) {
                $this->warn('Bybit API returned an error.');
                $this->line('Check the error message above for details.');
            } else {
                $this->warn('Possible causes:');
                $this->line('1. Wrong API keys in .env');
                $this->line('2. API keys don\'t have Read permission');
                $this->line('3. Keys from production instead of testnet');
                $this->line('4. Network issues');
            }

            return self::FAILURE;
        }
    }

    /**
     * Получить аккаунт для проверки
     */
    private function getAccount(): ?ExchangeAccount
    {
        // Если указан конкретный ID
        if ($accountId = $this->option('account')) {
            return ExchangeAccount::where('exchange', 'bybit')
                ->where('id', $accountId)
                ->first();
        }

        // Если указан testnet
        if ($this->option('testnet')) {
            return ExchangeAccount::where('exchange', 'bybit')
                ->where('is_testnet', true)
                ->first();
        }

        // Если указан production
        if ($this->option('production')) {
            return ExchangeAccount::where('exchange', 'bybit')
                ->where('is_testnet', false)
                ->first();
        }

        // По умолчанию - testnet (если есть), иначе production
        $testnetAccount = ExchangeAccount::where('exchange', 'bybit')
            ->where('is_testnet', true)
            ->first();

        if ($testnetAccount) {
            return $testnetAccount;
        }

        // Если testnet нет, берем production
        return ExchangeAccount::where('exchange', 'bybit')
            ->where('is_testnet', false)
            ->first();
    }

    /**
     * Показать доступные аккаунты
     */
    private function showAvailableAccounts(): void
    {
        $accounts = ExchangeAccount::where('exchange', 'bybit')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->line('  No accounts found. Run: php artisan setup');
            return;
        }

        foreach ($accounts as $account) {
            $type = $account->is_testnet ? 'Testnet' : 'Production';
            $this->line("  ID: {$account->id} - {$type}");
        }
    }
}
