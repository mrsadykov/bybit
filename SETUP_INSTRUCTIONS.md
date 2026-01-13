# Инструкции по настройке проекта

## 1. Проблема с версией PHP

У вас установлен PHP 8.2.27, а Laravel 12 требует PHP 8.4+. 

**Решение**: Я добавил `"platform-check": false` в `composer.json`, чтобы обойти проверку версии PHP. Это временное решение для разработки.

**Рекомендация**: Для продакшена лучше обновить PHP до 8.4+.

## 2. Откат и повторное применение миграций

Для отката всех миграций и повторного применения выполните:

```bash
# Откатить все миграции
php artisan migrate:rollback --step=100

# Или откатить все сразу (если нет важных данных)
php artisan migrate:fresh

# Затем применить миграции заново
php artisan migrate
```

**Внимание**: `migrate:fresh` удалит все данные из БД!

Если нужно сохранить данные, используйте:
```bash
# Откатить по одной миграции
php artisan migrate:rollback

# Или откатить несколько последних
php artisan migrate:rollback --step=5
```

## 3. Навигация по классам и методам в IDE

Для навигации по классам и методам в PhpStorm/IntelliJ IDEA:

### Автоматически работает:
- **Ctrl+Click** (Cmd+Click на Mac) на класс/метод - переход к определению
- **Ctrl+B** (Cmd+B на Mac) - переход к определению
- **Ctrl+Alt+B** (Cmd+Alt+B на Mac) - найти все реализации
- **Ctrl+Shift+T** (Cmd+Shift+T на Mac) - переключение между тестом и классом

### Если не работает:
1. **Переиндексировать проект**:
   - File → Invalidate Caches → Invalidate and Restart
   
2. **Проверить настройки Composer**:
   - Settings → PHP → Composer
   - Убедитесь, что путь к `composer` указан правильно
   
3. **Обновить индексы**:
   - File → Invalidate Caches → Invalidate and Restart
   
4. **Проверить автозагрузку**:
   ```bash
   composer dump-autoload
   ```

5. **Установить плагины** (если нужно):
   - Settings → Plugins
   - Убедитесь, что установлены:
     - PHP
     - Composer
     - Laravel (если есть)

### Полезные горячие клавиши:
- **Ctrl+N** (Cmd+O на Mac) - найти класс
- **Ctrl+Shift+N** (Cmd+Shift+O на Mac) - найти файл
- **Ctrl+Alt+Shift+N** (Cmd+Alt+O на Mac) - найти символ
- **Ctrl+Q** (Ctrl+J на Mac) - быстрая документация
- **Alt+F7** - найти использование

## 4. Запуск проекта

После исправления проблем с PHP:

```bash
# Очистить кэш
php artisan config:clear
php artisan cache:clear

# Запустить сервер
php artisan serve
```

Или используйте скрипт из composer.json:
```bash
composer run dev
```

## 5. Проверка команд

После настройки проверьте команды:

```bash
# Список всех команд
php artisan list

# Проверка конкретных команд
php artisan setup
php artisan create-bybit-account
php artisan bybit-bot:create BTCUSDT 5m rsi_ema 10
php artisan bots:run
```
