# Исправление отображения баланса для UNIFIED аккаунта

## Проблема

Для UNIFIED аккаунтов Bybit поле `availableToWithdraw` может быть **пустой строкой**, поэтому баланс показывается как 0.

## Решение

Используем `walletBalance` или `equity` как fallback, если `availableToWithdraw` пустое.

## Что исправлено

1. **getBalance()** - теперь использует `walletBalance` если `availableToWithdraw` пустое
2. **getAllBalances()** - аналогично для всех монет

## Структура ответа UNIFIED аккаунта

```json
{
  "coin": [
    {
      "coin": "USDT",
      "walletBalance": "14.4228939",
      "availableToWithdraw": "",  // ← Пустая строка!
      "equity": "14.4228939"
    }
  ]
}
```

## Что нужно сделать

1. **Сгенерируйте APP_KEY** (если еще не сделали):
   ```bash
   php artisan key:generate
   php artisan config:clear
   ```

2. **Пересоздайте аккаунты**:
   ```bash
   php artisan create-bybit-account --force
   ```

3. **Проверьте баланс**:
   ```bash
   php artisan balance:check USDT --account=НОВЫЙ_ID
   ```

## Результат

Теперь баланс должен показываться правильно:
- ✅ Использует `walletBalance` если `availableToWithdraw` пустое
- ✅ Показывает реальный баланс для UNIFIED аккаунтов
- ✅ Работает для всех монет
