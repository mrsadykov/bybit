# Исправление OKX Timestamp

## Проблема

Ошибка: `Invalid OK-ACCESS-TIMESTAMP` (code: 50112)

## Решение

OKX API v5 требует **ISO 8601 формат с миллисекундами**: `2024-01-12T18:55:10.123Z`

Формат:
- `Y-m-d\TH:i:s` - дата и время
- `.123` - миллисекунды (3 цифры)
- `Z` - UTC timezone

## Код для генерации

```php
$now = new \DateTime('now', new \DateTimeZone('UTC'));
$microseconds = (int) $now->format('u');
$milliseconds = str_pad((string)(int)($microseconds / 1000), 3, '0', STR_PAD_LEFT);
$timestamp = $now->format('Y-m-d\TH:i:s') . '.' . $milliseconds . 'Z';
```

## Важно

- Timestamp должен быть в UTC
- Должен быть синхронизирован с системным временем (расхождение не более 30 секунд)
- Для подписи используется тот же timestamp что и в заголовке
