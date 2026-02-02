<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $message,
        public ?string $parseMode = 'HTML',
        public ?string $chatId = null,
        public ?string $botToken = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TelegramService $telegram): void
    {
        $telegram->sendMessageSync(
            $this->message,
            $this->parseMode,
            $this->chatId,
            $this->botToken
        );
    }
}
