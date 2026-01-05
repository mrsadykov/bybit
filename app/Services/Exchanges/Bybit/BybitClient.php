<?php

namespace App\Services\Exchanges\Bybit;

use App\Models\ExchangeAccount;
use Illuminate\Support\Facades\Http;

class BybitClient
{
    public function __construct(
        protected ExchangeAccount $account
    ) {}

    protected function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->account->api_secret);
    }

    protected function request(
        string $method,
        string $endpoint,
        array $params = []
    ): array {
        $timestamp = (int) (microtime(true) * 1000);
        $recvWindow = config('exchanges.bybit.recv_window');

        $url = config('exchanges.bybit.base_url') . $endpoint;

        if ($method === 'GET') {
            $params = array_merge($params, [
                'api_key' => $this->account->api_key,
                'timestamp' => $timestamp,
                'recv_window' => $recvWindow,
            ]);

            ksort($params);
            $query = http_build_query($params);
            $params['sign'] = $this->sign($query);

            $response = Http::get($url, $params);
        } else {
            // POST
            $body = json_encode($params, JSON_UNESCAPED_SLASHES);

            $payload = $timestamp
                . $this->account->api_key
                . $recvWindow
                . $body;

            $sign = $this->sign($payload);

            $response = Http::withHeaders([
                'X-BAPI-API-KEY' => $this->account->api_key,
                'X-BAPI-TIMESTAMP' => $timestamp,
                'X-BAPI-RECV-WINDOW' => $recvWindow,
                'X-BAPI-SIGN' => $sign,
                'Content-Type' => 'application/json',
            ])->post($url, $params);
        }

        return $response->json();
    }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $params = []): array
    {
        return $this->request('POST', $endpoint, $params);
    }
}
