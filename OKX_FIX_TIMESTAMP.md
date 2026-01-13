# Исправление OKX API Timestamp

## Проблема

Ошибка: `Invalid OK-ACCESS-TIMESTAMP` (code: 50112)

## Решение

OKX API v5 требует:
- **OK-ACCESS-TIMESTAMP**: ISO 8601 формат в UTC (например: `2020-12-08T09:08:57.715Z`)
- **Для подписи**: Unix timestamp в секундах (как строка)

Важно: OKX может требовать ISO 8601 формат, а не Unix timestamp!

## Формат

```php
// ISO 8601 формат в UTC
$timestamp = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
// Результат: 2020-12-08T09:08:57.715Z
```

Или попробовать Unix timestamp в секундах (как строку):
```php
$timestamp = (string) time();
```

## Документация OKX

См. официальную документацию:
https://www.okx.com/docs-v5/en/#rest-api-authentication
