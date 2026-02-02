<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Models\FuturesBot;
use App\Models\TradingBot;
use App\Services\Exchanges\ExchangeServiceFactory;
use App\Services\Exchanges\OKX\OKXFuturesService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--no-alert : Не отправлять алерт в Telegram при сбое }';

    protected $description = 'Проверка OKX API, Telegram и последнего успешного запуска ботов; при сбое — алерт в Telegram';

    public function handle(): int
    {
        $failures = [];
        $maxMinutes = config('health.last_run_max_minutes', 15);
        $cooldownKey = 'health_alert_cooldown_until';

        // 1. OKX Spot API
        if (config('health.check_okx_spot', true)) {
            $account = TradingBot::whereHas('exchangeAccount', fn ($q) => $q->where('exchange', 'okx'))
                ->with('exchangeAccount')
                ->first()?->exchangeAccount
                ?? ExchangeAccount::where('exchange', 'okx')->first();

            if ($account) {
                try {
                    $service = ExchangeServiceFactory::create($account);
                    $service->getPrice('BTCUSDT');
                } catch (\Throwable $e) {
                    $failures[] = 'OKX Spot API: ' . $e->getMessage();
                }
            }
        }

        // 2. OKX Futures API (если есть активные фьючерсные боты)
        if (config('health.check_okx_futures', true)) {
            $futuresBot = FuturesBot::whereHas('exchangeAccount', fn ($q) => $q->where('exchange', 'okx'))
                ->with('exchangeAccount')
                ->first();

            if ($futuresBot && $futuresBot->exchangeAccount) {
                try {
                    $service = new OKXFuturesService($futuresBot->exchangeAccount);
                    $service->getPrice('BTCUSDT');
                } catch (\Throwable $e) {
                    $failures[] = 'OKX Futures API: ' . $e->getMessage();
                }
            }
        }

        // 3. Telegram API
        if (config('health.check_telegram', true)) {
            $token = config('services.telegram.health_bot_token') ?: config('services.telegram.bot_token');
            if ($token) {
                try {
                    $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");
                    if (! $response->successful() || ! ($response->json()['ok'] ?? false)) {
                        $failures[] = 'Telegram API: ответ не OK';
                    }
                } catch (\Throwable $e) {
                    $failures[] = 'Telegram API: ' . $e->getMessage();
                }
            }
        }

        // 4. Последний успешный запуск ботов (проверяем только включённые типы)
        $maxSeconds = $maxMinutes * 60;
        $runChecks = [
            'health_last_bots_run' => ['label' => 'bots:run', 'enabled' => true],
            'health_last_futures_run' => ['label' => 'futures:run', 'enabled' => config('futures.enabled', true)],
            'health_last_btc_quote_run' => ['label' => 'btc-quote:run', 'enabled' => config('btc_quote.enabled', true)],
        ];
        foreach ($runChecks as $key => $config) {
            if (! $config['enabled']) {
                continue;
            }
            $last = Cache::get($key);
            $label = $config['label'];
            if ($last === null) {
                $failures[] = "Последний успешный запуск {$label} не зафиксирован (кэш пуст)";
            } elseif (is_numeric($last) && (time() - (int) $last) > $maxSeconds) {
                $failures[] = "{$label}: последний успешный запуск более {$maxMinutes} мин назад";
            }
        }

        if (empty($failures)) {
            $this->info('Health check OK.');
            return self::SUCCESS;
        }

        $this->warn('Health check failed:');
        foreach ($failures as $f) {
            $this->line('  - ' . $f);
        }

        if (! $this->option('no-alert')) {
            $cooldownMinutes = config('health.alert_cooldown_minutes', 60);
            if (! Cache::has($cooldownKey) || Cache::get($cooldownKey) < time()) {
                try {
                    $telegram = new TelegramService();
                    $details = implode("\n", array_map(fn ($s) => '• ' . $s, $failures));
                    $telegram->notifyHealthAlert('Обнаружены сбои', $details);
                    Cache::put($cooldownKey, time() + $cooldownMinutes * 60, now()->addMinutes($cooldownMinutes + 5));
                } catch (\Throwable $e) {
                    $this->error('Не удалось отправить алерт в Telegram: ' . $e->getMessage());
                }
            }
        }

        return self::FAILURE;
    }
}
