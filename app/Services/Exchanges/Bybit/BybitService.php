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

//    public function __construct($exchangeAccount)
//    {
//        $this->apiKey    = $exchangeAccount->api_key;
//        $this->apiSecret = $exchangeAccount->api_secret;
//    }

    public function __construct(ExchangeAccount $account)
    {
//        $isTestnet =
//            (bool) $account->is_testnet ||
//            config('trading.bybit.env') === 'testnet';
//
//        $this->baseUrl = $isTestnet
//            ? 'https://api-testnet.bybit.com'
//            : 'https://api.bybit.com';

        $this->baseUrl = 'https://api.bybit.com';
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

    public function getCandles(string $symbol, int $interval, int $limit = 100): array
    {
        return $this->publicRequest('/v5/market/kline', [
            'category' => 'spot',
            'symbol'   => $symbol,
            'interval' => (string) $interval,
            'limit'    => $limit,
        ]);
    }

    /* ================= ORDERS ================= */

    // ✅ ЕДИНСТВЕННО ПРАВИЛЬНЫЙ BUY (Spot Market)
    public function placeMarketBuy(string $symbol, float $usdtAmount): array
    {
        return $this->privateRequest('/v5/order/create', [
            'category'       => 'spot',
            'symbol'         => $symbol,
            'side'           => 'Buy',
            'orderType'      => 'Market',
            'qty'  => (string) $usdtAmount,
            // quoteOrderQty
        ]);
    }

    // ✅ SELL (понадобится позже)
    public function placeMarketSell(string $symbol, float $btcQty): array
    {
        return $this->privateRequest('/v5/order/create', [
            'category'  => 'spot',
            'symbol'    => $symbol,
            'side'      => 'Sell',
            'orderType' => 'Market',
            'qty'       => (string) $btcQty,
        ]);
    }

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
        return $this->privateRequest('/v5/order/realtime', [
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

        // query string
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $payload = $timestamp . $this->apiKey . $recvWindow . $queryString;
        $sign    = hash_hmac('sha256', $payload, $this->apiSecret);

        $response = Http::timeout(10)
            ->withHeaders([
                'X-BAPI-API-KEY'     => $this->apiKey,
                'X-BAPI-SIGN'        => $sign,
                'X-BAPI-TIMESTAMP'   => $timestamp,
                'X-BAPI-RECV-WINDOW' => $recvWindow,
                'Content-Type'       => 'application/json',
                'User-Agent'         => 'BybitBot/1.0',
            ])
            ->get($this->baseUrl . $endpoint, $query);

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException(
                'Invalid private GET response from Bybit: ' . $response->body()
            );
        }

        return $json;
    }

    /*
    |--------------------------------------------------------------------------
    | GET ORDERS (SPOT)
    |--------------------------------------------------------------------------
    */
    public function getOrders(string $symbol, int $limit = 50): array
    {
        return $this->privateGetRequest('/v5/order/realtime', [
            'category' => 'spot',
            'symbol'   => $symbol,
            'limit'    => $limit,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ORDER HISTORY (SPOT)
    |--------------------------------------------------------------------------
    */
    public function getOrderHistory(string $symbol, int $limit = 50): array
    {
        return $this->privateGetRequest('/v5/order/history', [
            'category' => 'spot',
            'symbol'   => $symbol,
            'limit'    => $limit,
        ]);
    }
}
