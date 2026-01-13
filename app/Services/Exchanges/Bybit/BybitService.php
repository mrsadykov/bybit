<?php

namespace App\Services\Exchanges\Bybit;

use App\Models\ExchangeAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BybitService
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;

    public function __construct(ExchangeAccount $account)
    {
        // Используем is_testnet из модели аккаунта
        // config('trading.bybit.env') игнорируем, так как у каждого аккаунта свой тип
        $isTestnet = (bool) $account->is_testnet;

        $this->baseUrl = $isTestnet
            ? 'https://api-testnet.bybit.com'
            : 'https://api.bybit.com';

        $this->apiKey = $account->api_key;
        $this->apiSecret = $account->api_secret;
    }

    /* ================= PUBLIC ================= */

    private function publicRequest(string $endpoint, array $query = []): array
    {
        $response = Http::timeout(10)->get(
            $this->baseUrl . $endpoint,
            $query
        );

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('Invalid public response from Bybit');
        }

        return $json;
    }

    /* ================= PRIVATE ================= */

    private function privateRequest(string $endpoint, array $body): array
    {
        $timestamp  = (string) (int) (microtime(true) * 1000);
        $recvWindow = '5000';
        $bodyJson   = json_encode($body, JSON_UNESCAPED_SLASHES);

        $payload = $timestamp . $this->apiKey . $recvWindow . $bodyJson;
        $sign    = hash_hmac('sha256', $payload, $this->apiSecret);

        $response = Http::timeout(10)
            ->withHeaders([
                'X-BAPI-API-KEY'     => $this->apiKey,
                'X-BAPI-SIGN'        => $sign,
                'X-BAPI-TIMESTAMP'   => $timestamp,
                'X-BAPI-RECV-WINDOW' => $recvWindow,
                'Content-Type'       => 'application/json',
            ])
            ->post($this->baseUrl . $endpoint, $body);

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('Invalid private response from Bybit');
        }

        return $json;
    }

    /* ================= MARKET DATA ================= */

    public function getPrice(string $symbol): float
    {
        $data = $this->publicRequest('/v5/market/tickers', [
            'category' => 'spot',
            'symbol'   => $symbol,
        ]);

        if (
            ($data['retCode'] ?? 1) !== 0 ||
            empty($data['result']['list'][0]['lastPrice'])
        ) {
            throw new RuntimeException('Failed to get price: ' . json_encode($data));
        }

        return (float) $data['result']['list'][0]['lastPrice'];
    }

    public function getCandles(string $symbol, string $interval, int $limit = 100): array
    {
        return $this->publicRequest('/v5/market/kline', [
            'category' => 'spot',
            'symbol'   => $symbol,
            'interval' => $interval, // Bybit expects string: "1", "3", "5", "15", "30", "60", "120", "240", "360", "720", "D", "M", "W"
            'limit'    => $limit,
        ]);
    }

    /* ================= ORDERS ================= */

    // ✅ Market Buy для Spot (покупка на сумму в USDT)
    public function placeMarketBuy(string $symbol, float $usdtAmount): array
    {
        return $this->privateRequest('/v5/order/create', [
            'category'       => 'spot',
            'symbol'         => $symbol,
            'side'           => 'Buy',
            'orderType'      => 'Market',
            'quoteOrderQty'  => (string) $usdtAmount, // Для Market Buy используем quoteOrderQty (сумма в quote currency)
        ]);
    }

    // ✅ SELL (понадобится позже)
//    public function placeMarketSell(string $symbol, float $btcQty): array
//    {
//        return $this->privateRequest('/v5/order/create', [
//            'category'  => 'spot',
//            'symbol'    => $symbol,
//            'side'      => 'Sell',
//            'orderType' => 'Market',
//            'qty'       => (string) $btcQty,
//        ]);
//    }

    // ?
    public function placeMarketSellBtc(string $symbol, float $btcQty): array
    {
        return $this->privateRequest('/v5/order/create', [
            'category'  => 'spot',
            'symbol'    => $symbol,
            'side'      => 'Sell',
            'orderType' => 'Market',
            'qty'       => (string) $btcQty,
        ]);
    }

    public function getOrder(string $symbol, string $orderId): array
    {
        return $this->privateGetRequest('/v5/order/realtime', [
            'category' => 'spot',
            'symbol'   => $symbol,
            'orderId'  => $orderId,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRIVATE GET REQUEST (SIGNED)
    |--------------------------------------------------------------------------
    */
    private function privateGetRequest(string $endpoint, array $query): array
    {
        $timestamp  = (string) (int) (microtime(true) * 1000);
        $recvWindow = '5000';

        // query string - важно: сортировка и правильное кодирование
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        
        // Если query string пустой, используем пустую строку
        if (empty($queryString)) {
            $queryString = '';
        }

        // Формируем payload для подписи: timestamp + apiKey + recvWindow + queryString
        $payload = $timestamp . $this->apiKey . $recvWindow . $queryString;
        $sign    = hash_hmac('sha256', $payload, $this->apiSecret);
        
        // Для отладки (только в development)
        if (config('app.debug')) {
            \Log::debug('Bybit API Request', [
                'endpoint' => $endpoint,
                'query' => $query,
                'queryString' => $queryString,
                'payload' => $payload,
                'sign' => $sign,
                'timestamp' => $timestamp,
                'apiKey' => substr($this->apiKey, 0, 10) . '...',
                'apiSecret_length' => strlen($this->apiSecret),
                'full_url' => $this->baseUrl . $endpoint . ($queryString ? '?' . $queryString : ''),
            ]);
        }

        // Для GET запросов Content-Type обычно не нужен
        // Но проверим оба варианта
        $headers = [
            'X-BAPI-API-KEY'     => $this->apiKey,
            'X-BAPI-SIGN'        => $sign,
            'X-BAPI-TIMESTAMP'   => $timestamp,
            'X-BAPI-RECV-WINDOW' => $recvWindow,
            'User-Agent'         => 'BybitBot/1.0',
        ];
        
        $response = Http::timeout(10)
            ->withHeaders($headers)
            ->get($this->baseUrl . $endpoint, $query);

        // Проверяем HTTP статус
        if (! $response->successful()) {
            $status = $response->status();
            $body = $response->body();
            $json = $response->json();
            
            $errorDetails = 'Empty response';
            if ($json && is_array($json)) {
                $errorDetails = json_encode($json, JSON_PRETTY_PRINT);
            } elseif ($body) {
                $errorDetails = $body;
            }
            
            // Для 401 ошибки даем более детальную информацию
            if ($status === 401) {
                throw new \RuntimeException(
                    "Bybit API authentication failed (401 Unauthorized).\n" .
                    "This usually means:\n" .
                    "1. Wrong API key or secret\n" .
                    "2. API key doesn't have Read permission\n" .
                    "3. Invalid signature (check API secret)\n" .
                    "4. API keys from wrong environment (testnet vs production)\n\n" .
                    "Response: {$errorDetails}"
                );
            }
            
            throw new \RuntimeException(
                "Bybit API HTTP error: Status {$status}. Response: {$errorDetails}"
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            $body = $response->body();
            $status = $response->status();
            throw new \RuntimeException(
                "Invalid private GET response from Bybit. HTTP Status: {$status}. Response body: " . ($body ?: 'Empty')
            );
        }

        // Проверяем ошибки API
        if (($json['retCode'] ?? 0) !== 0) {
            $errorMsg = $json['retMsg'] ?? 'Unknown error';
            $retCode = $json['retCode'] ?? 'N/A';
            throw new \RuntimeException(
                "Bybit API error: {$errorMsg} (retCode: {$retCode})"
            );
        }

        return $json;
    }

    /*
    |--------------------------------------------------------------------------
    | GET ORDERS (SPOT)
    |--------------------------------------------------------------------------
    */
//    public function getOrders(string $symbol, int $limit = 50): array
//    {
//        return $this->privateGetRequest('/v5/order/realtime', [
//            'category' => 'spot',
//            'symbol'   => $symbol,
//            'limit'    => $limit,
//        ]);
//    }

    /*
    |--------------------------------------------------------------------------
    | ORDER HISTORY (SPOT)
    |--------------------------------------------------------------------------
    */
    public function getOrdersHistory(
        string $symbol = null,
        int $limit = 100,
        ?int $startTimeMs = null
    ): array
    {
        // Для /v5/order/history с category=spot accountType НЕ нужен
        // accountType используется только для фьючерсов и опционов
        $params = [
            'category' => 'spot',
            'limit'    => $limit,
        ];
        
        if ($symbol) {
            $params['symbol'] = $symbol;
        }
        
        if ($startTimeMs) {
            $params['startTime'] = $startTimeMs;
        }
        
        return $this->privateGetRequest('/v5/order/history', $params);
    }

    public function getExecutions(string $symbol, int $limit = 100): array
    {
        // Для spot executions accountType может быть не нужен
        // Пробуем сначала без accountType
        $params = [
            'category' => 'spot',
            'symbol'   => $symbol,
            'limit'    => $limit,
        ];
        
        try {
            return $this->privateGetRequest('/v5/execution/list', $params);
        } catch (\RuntimeException $e) {
            // Если ошибка, пробуем с accountType=UNIFIED
            $params['accountType'] = 'UNIFIED';
            return $this->privateGetRequest('/v5/execution/list', $params);
        }
    }

    public function getOrderHistory(string $symbol, string $orderId): array
    {
        return $this->privateGetRequest('/v5/order/history', [
            'category' => 'spot',
            'symbol'   => $symbol,
            'orderId'  => $orderId,
            'limit'    => 1
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCOUNT BALANCE (SPOT)
    |--------------------------------------------------------------------------
    */
    public function getBalance(string $coin = 'USDT'): float
    {
        // Для Bybit API v5 используем /v5/account/wallet-balance
        // Современные аккаунты Bybit используют UNIFIED
        // Пробуем сначала UNIFIED, затем SPOT (для старых аккаунтов)
        
        $accountTypes = ['UNIFIED', 'SPOT'];
        $response = null;
        $lastError = null;
        
        foreach ($accountTypes as $accountType) {
            try {
                $params = ['accountType' => $accountType];
                
                $response = $this->privateGetRequest('/v5/account/wallet-balance', $params);
                
                // Проверяем ответ API
                $retCode = $response['retCode'] ?? 1;
                $retMsg = $response['retMsg'] ?? '';
                
                // Если запрос успешен, выходим из цикла
                if ($retCode === 0) {
                    break;
                }
                
                // Если ошибка говорит что нужен UNIFIED, пробуем UNIFIED немедленно
                if (str_contains($retMsg, 'only support UNIFIED') || str_contains($retMsg, 'UNIFIED')) {
                    if ($accountType !== 'UNIFIED') {
                        // Пробуем UNIFIED
                        $params = ['accountType' => 'UNIFIED'];
                        $response = $this->privateGetRequest('/v5/account/wallet-balance', $params);
                        $retCode = $response['retCode'] ?? 1;
                        if ($retCode === 0) {
                            break;
                        }
                    }
                }
                
                // Сохраняем ошибку для последнего варианта
                $lastError = "retCode: {$retCode}, retMsg: {$retMsg}";
                
            } catch (\RuntimeException $e) {
                // Если это последний вариант, пробрасываем ошибку
                if ($accountType === end($accountTypes)) {
                    throw $e;
                }
                // Иначе пробуем следующий accountType
                $lastError = $e->getMessage();
                continue;
            }
        }

        if (!$response || ($response['retCode'] ?? 1) !== 0) {
            $errorMsg = $response['retMsg'] ?? ($lastError ?? 'Unknown error');
            throw new RuntimeException("Failed to get balance: {$errorMsg}. Response: " . json_encode($response));
        }

        $list = $response['result']['list'] ?? [];
        
        if (empty($list)) {
            // Логируем для диагностики
            if (config('app.debug')) {
                \Log::debug('Bybit getBalance: Empty list in response', [
                    'response' => $response,
                    'coin' => $coin,
                ]);
            }
            return 0.0;
        }

        // Берем первый аккаунт
        $account = $list[0] ?? [];
        $coins = $account['coin'] ?? [];

        // Логируем для диагностики
        if (config('app.debug')) {
            \Log::debug('Bybit getBalance: Account data', [
                'account' => $account,
                'coins_count' => count($coins),
                'totalWalletBalance' => $account['totalWalletBalance'] ?? 'N/A',
                'totalEquity' => $account['totalEquity'] ?? 'N/A',
                'coin' => $coin,
            ]);
        }

        // Если массив монет пустой, но есть totalWalletBalance - возможно монеты в другом формате
        if (empty($coins) && !empty($account['totalWalletBalance']) && (float) $account['totalWalletBalance'] > 0) {
            // Логируем для диагностики
            if (config('app.debug')) {
                \Log::warning('Bybit getBalance: Coins array is empty but totalWalletBalance > 0', [
                    'totalWalletBalance' => $account['totalWalletBalance'],
                    'totalEquity' => $account['totalEquity'] ?? 'N/A',
                    'account' => $account,
                ]);
            }
            
            // Если запрашиваем USDT и есть totalWalletBalance, возвращаем его
            // (для UNIFIED аккаунта totalWalletBalance обычно в USDT)
            if ($coin === 'USDT') {
                return (float) ($account['totalWalletBalance'] ?? 0);
            }
        }

        // Ищем нужную монету
        foreach ($coins as $coinData) {
            if (($coinData['coin'] ?? '') === $coin) {
                // Для UNIFIED аккаунта availableToWithdraw может быть пустой строкой
                // Используем walletBalance или equity как fallback
                $availableToWithdraw = $coinData['availableToWithdraw'] ?? '';
                $walletBalance = $coinData['walletBalance'] ?? '0';
                $equity = $coinData['equity'] ?? '0';
                
                // Логируем для диагностики
                if (config('app.debug')) {
                    \Log::debug('Bybit getBalance: Coin data', [
                        'coin' => $coin,
                        'availableToWithdraw' => $availableToWithdraw,
                        'walletBalance' => $walletBalance,
                        'equity' => $equity,
                    ]);
                }
                
                // Если availableToWithdraw пустая строка или 0, используем walletBalance
                if ($availableToWithdraw === '' || (float) $availableToWithdraw === 0.0) {
                    $available = (float) ($walletBalance ?: $equity);
                } else {
                    $available = (float) $availableToWithdraw;
                }
                
                return $available;
            }
        }

        // Логируем если монета не найдена
        if (config('app.debug')) {
            \Log::debug('Bybit getBalance: Coin not found', [
                'coin' => $coin,
                'available_coins' => array_column($coins, 'coin'),
            ]);
        }

        return 0.0;
    }

    /**
     * Получить баланс всех монет
     */
    public function getAllBalances(): array
    {
        // Современные аккаунты Bybit используют UNIFIED
        // Пробуем сначала UNIFIED, затем SPOT
        $accountTypes = ['UNIFIED', 'SPOT'];
        $response = null;
        $lastError = null;
        
        foreach ($accountTypes as $accountType) {
            try {
                $params = ['accountType' => $accountType];
                $response = $this->privateGetRequest('/v5/account/wallet-balance', $params);
                
                $retCode = $response['retCode'] ?? 1;
                $retMsg = $response['retMsg'] ?? '';
                
                // Если запрос успешен, выходим из цикла
                if ($retCode === 0) {
                    break;
                }
                
                // Если ошибка говорит что нужен UNIFIED, пробуем UNIFIED немедленно
                if (str_contains($retMsg, 'only support UNIFIED') || str_contains($retMsg, 'UNIFIED')) {
                    if ($accountType !== 'UNIFIED') {
                        $params = ['accountType' => 'UNIFIED'];
                        $response = $this->privateGetRequest('/v5/account/wallet-balance', $params);
                        $retCode = $response['retCode'] ?? 1;
                        if ($retCode === 0) {
                            break;
                        }
                    }
                }
                
                $lastError = "retCode: {$retCode}, retMsg: {$retMsg}";
                
            } catch (\RuntimeException $e) {
                if ($accountType === end($accountTypes)) {
                    throw $e;
                }
                $lastError = $e->getMessage();
                continue;
            }
        }
        
        if (!$response) {
            throw new RuntimeException('Failed to get balances: No response');
        }

        if (($response['retCode'] ?? 1) !== 0) {
            throw new RuntimeException('Failed to get balances: ' . json_encode($response));
        }

        $list = $response['result']['list'] ?? [];
        
        if (empty($list)) {
            return [];
        }

        $account = $list[0] ?? [];
        $coins = $account['coin'] ?? [];

        $balances = [];
        foreach ($coins as $coinData) {
            $coin = $coinData['coin'] ?? '';
            
            // Для UNIFIED аккаунта availableToWithdraw может быть пустой строкой
            // Используем walletBalance или equity как fallback
            $availableToWithdraw = $coinData['availableToWithdraw'] ?? '';
            $walletBalance = $coinData['walletBalance'] ?? '0';
            $equity = $coinData['equity'] ?? '0';
            
            // Если availableToWithdraw пустая строка или 0, используем walletBalance
            if ($availableToWithdraw === '' || (float) $availableToWithdraw === 0.0) {
                $available = (float) ($walletBalance ?: $equity);
            } else {
                $available = (float) $availableToWithdraw;
            }
            
            if ($available > 0) {
                $balances[$coin] = $available;
            }
        }

        return $balances;
    }
}
