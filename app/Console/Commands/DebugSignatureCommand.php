<?php

namespace App\Console\Commands;

use App\Models\ExchangeAccount;
use App\Services\Exchanges\Bybit\BybitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DebugSignatureCommand extends Command
{
    protected $signature = 'debug:signature {--account= : Account ID}';
    protected $description = 'Debug Bybit API signature generation';

    public function handle(): int
    {
        $accountId = $this->option('account');
        
        if (!$accountId) {
            $this->error('Please specify account ID: --account=ID');
            $this->line('Available accounts:');
            $accounts = ExchangeAccount::all(['id', 'exchange', 'is_testnet']);
            foreach ($accounts as $acc) {
                $type = $acc->is_testnet ? 'Testnet' : 'Production';
                $this->line("  ID: {$acc->id}, Type: {$type}");
            }
            return self::FAILURE;
        }

        $account = ExchangeAccount::find($accountId);
        
        if (!$account) {
            $this->error("Account #{$accountId} not found");
            return self::FAILURE;
        }

        $this->info("🔍 Debugging signature for Account #{$accountId}");
        $this->line('Testnet: ' . ($account->is_testnet ? 'Yes' : 'No'));
        $this->line('Base URL: ' . ($account->is_testnet ? 'https://api-testnet.bybit.com' : 'https://api.bybit.com'));
        $this->line('');

        $apiKey = $account->api_key;
        $apiSecret = $account->api_secret;
        
        $this->info('📋 API Key Info:');
        $this->line('  Key: ' . substr($apiKey, 0, 10) . '...' . substr($apiKey, -5));
        $this->line('  Key Length: ' . strlen($apiKey));
        $this->line('  Secret Length: ' . strlen($apiSecret));
        $this->line('');

        // Тестируем формирование подписи
        // Современные аккаунты Bybit используют UNIFIED
        $endpoint = '/v5/account/wallet-balance';
        $query = ['accountType' => 'UNIFIED'];
        
        $timestamp = (string) (int) (microtime(true) * 1000);
        $recvWindow = '5000';
        
        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        
        $payload = $timestamp . $apiKey . $recvWindow . $queryString;
        $sign = hash_hmac('sha256', $payload, $apiSecret);
        
        $this->info('🔐 Signature Details:');
        $this->line('  Endpoint: ' . $endpoint);
        $this->line('  Query: ' . json_encode($query));
        $this->line('  Query String: ' . $queryString);
        $this->line('  Timestamp: ' . $timestamp);
        $this->line('  Recv Window: ' . $recvWindow);
        $this->line('  Payload: ' . $payload);
        $this->line('  Signature: ' . $sign);
        $this->line('');

        $baseUrl = $account->is_testnet ? 'https://api-testnet.bybit.com' : 'https://api.bybit.com';
        $fullUrl = $baseUrl . $endpoint . ($queryString ? '?' . $queryString : '');
        
        $this->info('🌐 Request Details:');
        $this->line('  Full URL: ' . $fullUrl);
        $this->line('  Headers:');
        $this->line('    X-BAPI-API-KEY: ' . substr($apiKey, 0, 10) . '...');
        $this->line('    X-BAPI-SIGN: ' . $sign);
        $this->line('    X-BAPI-TIMESTAMP: ' . $timestamp);
        $this->line('    X-BAPI-RECV-WINDOW: ' . $recvWindow);
        $this->line('');

        // Пробуем отправить запрос
        $this->info('📤 Sending test request...');
        
        // Пробуем сначала UNIFIED, затем SPOT если не работает
        $accountTypes = ['UNIFIED', 'SPOT'];
        $success = false;
        
        foreach ($accountTypes as $accountType) {
            try {
                $testQuery = ['accountType' => $accountType];
                ksort($testQuery);
                $testQueryString = http_build_query($testQuery, '', '&', PHP_QUERY_RFC3986);
                
                $testPayload = $timestamp . $apiKey . $recvWindow . $testQueryString;
                $testSign = hash_hmac('sha256', $testPayload, $apiSecret);
                
                $testUrl = $baseUrl . $endpoint . ($testQueryString ? '?' . $testQueryString : '');
                
                $this->line("  Trying accountType: {$accountType}...");
                
                $response = Http::timeout(10)
                    ->withHeaders([
                        'X-BAPI-API-KEY'     => $apiKey,
                        'X-BAPI-SIGN'        => $testSign,
                        'X-BAPI-TIMESTAMP'   => $timestamp,
                        'X-BAPI-RECV-WINDOW' => $recvWindow,
                        'Content-Type'       => 'application/json',
                        'User-Agent'         => 'BybitBot/1.0',
                    ])
                    ->get($testUrl);

                $this->line('  HTTP Status: ' . $response->status());
                
                $json = $response->json();
                if ($json) {
                    $this->line('  Response JSON:');
                    $this->line('    ' . json_encode($json, JSON_PRETTY_PRINT));
                    
                    if (isset($json['retCode'])) {
                        $this->line('');
                        if ($json['retCode'] === 0) {
                            $this->info("✅ Request successful with accountType: {$accountType}!");
                            $success = true;
                            break;
                        } else {
                            $retMsg = $json['retMsg'] ?? 'N/A';
                            $this->warn("  ❌ Failed with {$accountType}: {$retMsg}");
                            
                            // Если ошибка говорит что нужен UNIFIED, пробуем UNIFIED
                            if (str_contains($retMsg, 'only support UNIFIED') && $accountType !== 'UNIFIED') {
                                $this->line('  → Retrying with UNIFIED...');
                                continue;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("  ❌ Exception with {$accountType}: " . $e->getMessage());
                if ($accountType === end($accountTypes)) {
                    $this->error('❌ All account types failed!');
                }
            }
        }
        
        if (!$success) {
            $this->error('❌ Could not get balance with any account type');
        }

        return self::SUCCESS;
    }
}
