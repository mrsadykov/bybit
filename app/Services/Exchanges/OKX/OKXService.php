<?php

namespace App\Services\Exchanges\OKX;

use App\Models\ExchangeAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Illuminate\Support\Facades\Log;

class OKXService
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $passphrase;

    public function __construct(ExchangeAccount $account)
    {
        $this->baseUrl = config('exchanges.okx.base_url', 'https://www.okx.com');
        $this->apiKey = $account->api_key;
        $this->apiSecret = $account->api_secret;
        $this->passphrase = config('services.okx.passphrase', '');
    }

    /* ================= PUBLIC ================= */

    private function publicRequest(string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . '/api/v5' . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $response = Http::timeout(10)->get($url);
        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException('Invalid public response from OKX');
        }

        // OKX использует code вместо retCode
        if (($json['code'] ?? '0') !== '0') {
            $msg = $json['msg'] ?? 'Unknown error';
            throw new RuntimeException("OKX API error: {$msg}");
        }

        return $json;
    }

    /* ================= PRIVATE ================= */

    private function privateRequest(string $method, string $endpoint, array $body = []): array
    {
        $requestPath = '/api/v5' . $endpoint;

        // OKX требует ISO 8601 формат timestamp в UTC с миллисекундами: 2024-01-12T18:55:10.123Z
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $microseconds = (int) $now->format('u');
        $milliseconds = str_pad((string)(int)($microseconds / 1000), 3, '0', STR_PAD_LEFT);
        $timestamp = $now->format('Y-m-d\TH:i:s') . '.' . $milliseconds . 'Z';
        
        // Для GET запросов параметры в query string
        // Для POST/PUT тело запроса в JSON
        $bodyJson = '';
        $queryParams = [];
        
        if ($method === 'GET' && !empty($body)) {
            $queryParams = $body;
            // Сортируем параметры для query string
            ksort($queryParams);
            if (!empty($queryParams)) {
                $queryString = http_build_query($queryParams);
                $requestPath .= '?' . $queryString;
            }
        } elseif (!empty($body)) {
            $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        
        // Формируем строку для подписи: timestamp + method + requestPath + body
        $message = $timestamp . strtoupper($method) . $requestPath . $bodyJson;
        $sign = base64_encode(hash_hmac('sha256', $message, $this->apiSecret, true));

        $headers = [
            'OK-ACCESS-KEY' => $this->apiKey,
            'OK-ACCESS-SIGN' => $sign,
            'OK-ACCESS-TIMESTAMP' => $timestamp,
            'OK-ACCESS-PASSPHRASE' => $this->passphrase,
        ];

        // Content-Type только для POST/PUT
        if ($method !== 'GET') {
            $headers['Content-Type'] = 'application/json';
        }

        $url = $this->baseUrl . $requestPath;

        if ($method === 'GET') {
            $response = Http::timeout(10)->withHeaders($headers)->get($url);
        } else {
            $response = Http::timeout(10)->withHeaders($headers)->$method($url, $body);
        }

        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->body();
            throw new RuntimeException("OKX API HTTP error: Status {$status}. Response: {$body}");
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException('Invalid private response from OKX');
        }

        // OKX использует code вместо retCode
        if (($json['code'] ?? '0') !== '0') {
            $msg = $json['msg'] ?? 'Unknown error';
            throw new RuntimeException("OKX API error: {$msg}");
        }

        return $json;
    }

    private function privateGet(string $endpoint, array $query = []): array
    {
        return $this->privateRequest('GET', $endpoint, $query);
    }

    private function privatePost(string $endpoint, array $body = []): array
    {
        return $this->privateRequest('POST', $endpoint, $body);
    }

    /* ================= MARKET DATA ================= */

    public function getPrice(string $symbol): float
    {
        // OKX использует формат BTC-USDT вместо BTCUSDT
        $okxSymbol = $this->formatSymbol($symbol);
        
        $data = $this->publicRequest('/market/ticker', [
            'instId' => $okxSymbol,
        ]);

        if (empty($data['data'][0]['last'])) {
            throw new RuntimeException('Failed to get price: ' . json_encode($data));
        }

        return (float) $data['data'][0]['last'];
    }

    public function getCandles(string $symbol, string $interval, int $limit = 100): array
    {
        $okxSymbol = $this->formatSymbol($symbol);
        $okxInterval = $this->formatInterval($interval);

        return $this->publicRequest('/market/candles', [
            'instId' => $okxSymbol,
            'bar' => $okxInterval,
            'limit' => (string) $limit,
        ]);
    }

    /* ================= ACCOUNT ================= */

    public function getBalance(string $coin = 'USDT'): float
    {
        $data = $this->privateGet('/account/balance', [
            'ccy' => $coin,
        ]);

        $details = $data['data'][0]['details'][0] ?? null;
        if (!$details) {
            return 0.0;
        }

        // OKX использует availBal (available balance)
        return (float) ($details['availBal'] ?? 0);
    }

    /* ================= HELPER METHODS ================= */

    /**
     * Конвертирует BTCUSDT в BTC-USDT (формат OKX)
     */
    private function formatSymbol(string $symbol): string
    {
        // Если уже есть дефис, возвращаем как есть
        if (strpos($symbol, '-') !== false) {
            return $symbol;
        }

        // Для BTCUSDT -> BTC-USDT
        if (str_ends_with($symbol, 'USDT')) {
            $base = substr($symbol, 0, -4);
            return $base . '-USDT';
        }

        return $symbol;
    }

    /**
     * Конвертирует интервал из формата Bybit в формат OKX
     */
    private function formatInterval(string $interval): string
    {
        $map = [
            '1' => '1m',
            '3' => '3m',
            '5' => '5m',
            '15' => '15m',
            '30' => '30m',
            '60' => '1H',
            '120' => '2H',
            '240' => '4H',
            '360' => '6H',
            '720' => '12H',
            'D' => '1D',
            'W' => '1W',
            'M' => '1M',
        ];

        return $map[$interval] ?? $interval;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
