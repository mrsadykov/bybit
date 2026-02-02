<?php

namespace App\Support;

/**
 * Повтор вызова с экспоненциальной задержкой при исключении.
 * Используется для API-запросов (getPrice, getCandles и т.д.).
 */
class RetryHelper
{
    /**
     * Выполнить callback до maxAttempts раз; при исключении — пауза и повтор.
     *
     * @param  callable  $callback  Без аргументов, возвращает результат
     * @param  int  $maxAttempts  Максимум попыток (по умолчанию 3)
     * @param  int  $baseDelayMs  Базовая задержка в мс перед повтором (удваивается каждый раз)
     * @return mixed
     *
     * @throws \Throwable Последнее исключение после исчерпания попыток
     */
    public static function retry(callable $callback, int $maxAttempts = 3, int $baseDelayMs = 1000): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                $delayMs = $baseDelayMs * (2 ** ($attempt - 1));
                usleep($delayMs * 1000);
            }
        }

        throw $lastException;
    }
}
