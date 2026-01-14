<?php

namespace App\Console\Commands;

use App\Models\Trade;
use App\Models\TradingBot;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class SendDailyStatsCommand extends Command
{
    protected $signature = 'telegram:daily-stats {--date= : Date for statistics (Y-m-d format, default: today)}';
    protected $description = 'Send daily trading statistics to Telegram';

    public function handle(): int
    {
        $dateInput = $this->option('date');
        $date = $dateInput ? \Carbon\Carbon::parse($dateInput)->format('Y-m-d') : now()->format('Y-m-d');

        $this->info("Расчет ежедневной статистики за {$date} (Calculating daily statistics for {$date})...");
        $this->line('');

        // Получаем всех пользователей с активными ботами
        $users = \App\Models\User::whereHas('tradingBots', function ($query) {
            $query->where('is_active', true);
        })->get();

        if ($users->isEmpty()) {
            $this->warn('Пользователей с активными ботами не найдено (No users with active bots found).');
            return self::SUCCESS;
        }

        $sentCount = 0;

        foreach ($users as $user) {
            $stats = $this->calculateDailyStats($user->id, $date);

            if ($stats['total_trades'] === 0 && $stats['closed_positions'] === 0) {
                $this->line("Пользователь #{$user->id} ({$user->email}): Нет сделок или закрытых позиций за {$date} (User #{$user->id} ({$user->email}): No trades or closed positions for {$date})");
                continue;
            }

            $telegram = new TelegramService();
            $telegram->notifyDailyStats($stats);

            $this->info("✅ Статистика отправлена пользователю #{$user->id} ({$user->email}) (Statistics sent to user #{$user->id} ({$user->email}))");
            $sentCount++;

            // Небольшая задержка между отправками, чтобы не перегружать Telegram API
            if ($users->count() > 1) {
                usleep(500_000); // 0.5 секунды
            }
        }

        $this->line('');
        $this->info("Ежедневная статистика отправлена {$sentCount} пользователю(ам) (Daily statistics sent to {$sentCount} user(s)).");

        return self::SUCCESS;
    }

    protected function calculateDailyStats(int $userId, string $date): array
    {
        // Получаем все боты пользователя
        $botIds = TradingBot::where('user_id', $userId)->pluck('id');

        if ($botIds->isEmpty()) {
            return $this->getEmptyStats($date);
        }

        // Статистика по закрытым позициям за день
        $closedPositions = Trade::whereIn('trading_bot_id', $botIds)
            ->whereNotNull('closed_at')
            ->whereNotNull('realized_pnl')
            ->whereDate('closed_at', $date)
            ->get();

        $totalPnL = $closedPositions->sum('realized_pnl');
        $winningTrades = $closedPositions->where('realized_pnl', '>', 0)->count();
        $losingTrades = $closedPositions->where('realized_pnl', '<', 0)->count();
        $winRate = $closedPositions->count() > 0
            ? round(($winningTrades / $closedPositions->count()) * 100, 2)
            : 0;

        // Общее количество сделок за день (BUY + SELL)
        $totalTrades = Trade::whereIn('trading_bot_id', $botIds)
            ->whereDate('created_at', $date)
            ->count();

        // Открытые позиции
        $openPositions = Trade::whereIn('trading_bot_id', $botIds)
            ->where('side', 'BUY')
            ->where('status', 'FILLED')
            ->whereNull('closed_at')
            ->count();

        // Активные боты
        $activeBots = TradingBot::where('user_id', $userId)
            ->where('is_active', true)
            ->count();

        return [
            'date' => $date,
            'total_pnl' => (float) $totalPnL,
            'winning_trades' => $winningTrades,
            'losing_trades' => $losingTrades,
            'total_trades' => $totalTrades,
            'win_rate' => $winRate,
            'closed_positions' => $closedPositions->count(),
            'open_positions' => $openPositions,
            'active_bots' => $activeBots,
        ];
    }

    protected function getEmptyStats(string $date): array
    {
        return [
            'date' => $date,
            'total_pnl' => 0,
            'winning_trades' => 0,
            'losing_trades' => 0,
            'total_trades' => 0,
            'win_rate' => 0,
            'closed_positions' => 0,
            'open_positions' => 0,
            'active_bots' => 0,
        ];
    }
}
