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
            $code = $json['code'] ?? 'Unknown';
            $msg = $json['msg'] ?? 'Unknown error';
            $errorData = $json['data'] ?? null;
            $errorDetails = $code . ': ' . $msg;
            if ($errorData) {
                $errorDetails .= ' | Data: ' . json_encode($errorData);
            }
            throw new RuntimeException("OKX API error: {$errorDetails}");
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

    /**
     * Получить баланс всех монет
     */
    public function getAllBalances(): array
    {
        // Получаем все балансы без фильтра по монете
        $data = $this->privateGet('/account/balance', []);

        $balances = [];
        
        if (empty($data['data'])) {
            return $balances;
        }

        // Обрабатываем все аккаунты
        foreach ($data['data'] as $account) {
            $details = $account['details'] ?? [];
            
            foreach ($details as $detail) {
                $coin = $detail['ccy'] ?? '';
                $availBal = (float) ($detail['availBal'] ?? 0);
                
                if ($coin && $availBal > 0) {
                    // Если монета уже есть, суммируем балансы
                    if (isset($balances[$coin])) {
                        $balances[$coin] += $availBal;
                    } else {
                        $balances[$coin] = $availBal;
                    }
                }
            }
        }

        return $balances;
    }

    /* ================= ORDERS ================= */

    /**
     * Market Buy для Spot (покупка на сумму в USDT)
     * 
     * @param string $symbol Торговая пара (например: BTCUSDT)
     * @param float $usdtAmount Сумма в USDT для покупки
     * @return array Ответ от OKX API
     */
    public function placeMarketBuy(string $symbol, float $usdtAmount): array
    {
        $okxSymbol = $this->formatSymbol($symbol);
        
        // OKX API v5: для Market Buy на сумму используем tgtCcy: "quote_ccy"
        $response = $this->privatePost('/trade/order', [
            'instId' => $okxSymbol,
            'tdMode' => 'cash', // cash для spot
            'side' => 'buy',
            'ordType' => 'market',
            'sz' => (string) $usdtAmount, // Размер в quote currency (USDT)
            'tgtCcy' => 'quote_ccy', // Покупаем на сумму в quote currency
        ]);

        return $response;
    }

    /**
     * Market Sell для Spot (продажа BTC)
     * 
     * @param string $symbol Торговая пара (например: BTCUSDT)
     * @param float $btcQty Количество BTC для продажи
     * @return array Ответ от OKX API
     */
    public function placeMarketSellBtc(string $symbol, float $btcQty): array
    {
        $okxSymbol = $this->formatSymbol($symbol);
        
        // OKX API v5: для Market Sell используем sz (размер) в base currency
        $response = $this->privatePost('/trade/order', [
            'instId' => $okxSymbol,
            'tdMode' => 'cash', // cash для spot
            'side' => 'sell',
            'ordType' => 'market',
            'sz' => (string) $btcQty, // Количество в base currency (BTC)
            'tgtCcy' => 'base_ccy', // Продаем в base currency
        ]);

        return $response;
    }

    /**
     * Получить информацию об ордере
     * 
     * @param string $symbol Торговая пара (например: BTCUSDT)
     * @param string $orderId ID ордера
     * @return array Ответ от OKX API
     */
    public function getOrder(string $symbol, string $orderId): array
    {
        $okxSymbol = $this->formatSymbol($symbol);
        
        $response = $this->privateGet('/trade/order', [
            'instId' => $okxSymbol,
            'ordId' => $orderId,
        ]);

        return $response;
    }

    /**
     * Получить историю ордеров (для заполненных ордеров)
     * 
     * @param string $symbol Торговая пара (например: BTCUSDT)
     * @param string|null $orderId ID ордера (опционально)
     * @return array Ответ от OKX API
     */
    public function getOrderHistory(string $symbol, ?string $orderId = null): array
    {
        $okxSymbol = $this->formatSymbol($symbol);
        
        $params = [
            'instType' => 'SPOT', // Обязательный параметр для OKX
            'instId' => $okxSymbol,
            'state' => 'filled', // Только заполненные ордера
        ];
        
        if ($orderId) {
            $params['ordId'] = $orderId;
        }
        
        $response = $this->privateGet('/trade/orders-history', $params);

        return $response;
    }

    /**
     * Получить историю всех ордеров (для восстановления)
     * 
     * @param string|null $symbol Торговая пара (опционально, null = все символы)
     * @param int $limit Лимит ордеров (максимум 100)
     * @return array Ответ от OKX API
     */
    public function getOrdersHistory(?string $symbol = null, int $limit = 100): array
    {
        $params = [
            'instType' => 'SPOT', // Обязательный параметр для OKX
            'limit' => (string) min($limit, 100), // OKX максимум 100
        ];
        
        if ($symbol) {
            $params['instId'] = $this->formatSymbol($symbol);
        }
        
        // Используем orders-history для получения всех ордеров (включая заполненные и отмененные)
        // Если нужны только заполненные, можно добавить 'state' => 'filled'
        // Но для восстановления нужны все ордера
        $response = $this->privateGet('/trade/orders-history', $params);

        return $response;
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
     * Конвертирует интервал из формата Bybit/универсального в формат OKX
     * Поддерживает форматы: "1h", "5m", "15m", "60" и т.д.
     */
    private function formatInterval(string $interval): string
    {
        // Нормализуем входной формат (убираем пробелы)
        $interval = trim($interval);
        $intervalLower = strtolower($interval);
        
        // Если формат с буквой (например "1h", "5m", "15m", "1H")
        if (preg_match('/^(\d+)([mhdwM])$/i', $intervalLower, $matches)) {
            $value = (int)$matches[1];
            $unit = strtolower($matches[2]);
            
            // Часы (h или H)
            if ($unit === 'h') {
                $valueInMinutes = $value * 60;
            }
            // Минуты (m - но не M для месяца)
            elseif ($unit === 'm' && $value <= 60) {
                $valueInMinutes = $value;
            }
            // Дни (d или D)
            elseif ($unit === 'd') {
                return '1D';
            }
            // Недели (w или W)
            elseif ($unit === 'w') {
                return '1W';
            }
            // Месяцы (M - заглавная)
            elseif ($unit === 'm' && strtoupper($interval) === $interval && $value > 60) {
                return '1M';
            }
            // По умолчанию - минуты
            else {
                $valueInMinutes = $value;
            }
        }
        // Если просто число (например "60", "15")
        else {
            $valueInMinutes = (int)$interval;
        }
        
        // Маппинг минут в формат OKX
        $map = [
            1 => '1m',
            3 => '3m',
            5 => '5m',
            15 => '15m',
            30 => '30m',
            60 => '1H',
            120 => '2H',
            240 => '4H',
            360 => '6H',
            720 => '12H',
        ];
        
        // Если значение найдено в маппинге
        if (isset($map[$valueInMinutes])) {
            return $map[$valueInMinutes];
        }
        
        // Если не найдено, возвращаем как есть (может быть уже в формате OKX)
        // Или пытаемся нормализовать часы
        if (preg_match('/^(\d+)[hH]$/i', $interval)) {
            return strtoupper($interval); // "1h" -> "1H"
        }
        
        return $interval;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
