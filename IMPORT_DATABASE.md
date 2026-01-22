# 📥 Импорт базы данных из trading_bot.sql

## ⚠️ ВНИМАНИЕ

**Это удалит все текущие данные из базы данных!**

Перед импортом убедитесь, что:
- ✅ У вас есть backup текущей БД (если нужны данные)
- ✅ Имя базы данных в `.env` правильное
- ✅ У вас есть доступ к MySQL

---

## 📋 Шаги импорта

### 1. Проверьте настройки БД в `.env`

```bash
# Откройте .env и проверьте:
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=trading_bot  # Или ваше имя БД
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 2. Создайте базу данных (если не существует)

```bash
mysql -u your_username -p
```

В MySQL:
```sql
CREATE DATABASE IF NOT EXISTS trading_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

### 3. Импортируйте SQL файл

**Вариант A: Через командную строку (рекомендуется)**

```bash
mysql -u your_username -p trading_bot < trading_bot.sql
```

Или с указанием хоста:
```bash
mysql -h 127.0.0.1 -u your_username -p trading_bot < trading_bot.sql
```

**Вариант B: Через MySQL клиент**

```bash
mysql -u your_username -p trading_bot
```

В MySQL:
```sql
SOURCE /Users/iskandarsadykov/PhpstormProjects/bybit/trading_bot.sql;
EXIT;
```

**Вариант C: Через phpMyAdmin/Adminer**

1. Откройте phpMyAdmin/Adminer
2. Выберите базу данных `trading_bot`
3. Перейдите на вкладку "Импорт"
4. Выберите файл `trading_bot.sql`
5. Нажмите "Выполнить"

---

## ✅ После импорта

### 1. Примените недостающую миграцию

SQL файл не содержит миграцию для `rsi_period` и `ema_period`. Примените её:

```bash
php artisan migrate
```

Это добавит поля `rsi_period` и `ema_period` в таблицу `trading_bots`.

### 2. Проверьте данные

```bash
php artisan tinker
```

В tinker:
```php
// Проверить пользователей
\App\Models\User::count();
\App\Models\User::all();

// Проверить аккаунты бирж
\App\Models\ExchangeAccount::count();
\App\Models\ExchangeAccount::all();

// Проверить ботов
\App\Models\TradingBot::count();
\App\Models\TradingBot::with('exchangeAccount')->get();

// Проверить сделки
\App\Models\Trade::count();
\App\Models\Trade::with('bot')->get();
```

### 3. Проверьте миграции

```bash
php artisan migrate:status
```

Должны быть применены все миграции, включая новую для `rsi_period`/`ema_period`.

---

## 🔍 Что содержит SQL файл

- ✅ **users** - 1 пользователь (admin)
- ✅ **exchange_accounts** - 1 OKX аккаунт
- ✅ **trading_bots** - 3 бота (BTCUSDT, ETHUSDT, SOLUSDT)
- ✅ **trades** - 16 сделок
- ✅ **migrations** - миграции до batch 3
- ✅ **sessions** - активные сессии

---

## ⚠️ Важные замечания

### 1. Миграция rsi_period/ema_period

SQL файл **НЕ содержит** миграцию для полей `rsi_period` и `ema_period`. После импорта:

```bash
php artisan migrate
```

Это применит недостающую миграцию `2026_01_19_154606_add_rsi_ema_periods_to_trading_bots_table.php`.

### 2. API ключи

SQL файл содержит зашифрованные API ключи OKX. Они должны работать, если:
- ✅ Используется тот же `APP_KEY` что и при создании дампа
- ✅ Или пересоздайте аккаунт: `php artisan create-okx-account`

### 3. Пароль пользователя

Пароль пользователя захеширован. Если нужно изменить:

```bash
php artisan tinker
```

```php
$user = \App\Models\User::find(1);
$user->password = bcrypt('новый_пароль');
$user->save();
```

---

## 🔄 Альтернативный способ (через Laravel)

Если хотите использовать Laravel команды:

```bash
# 1. Очистить БД
php artisan migrate:fresh

# 2. Импортировать SQL
mysql -u your_username -p trading_bot < trading_bot.sql

# 3. Применить недостающие миграции
php artisan migrate
```

---

## ✅ Проверка после импорта

```bash
# Проверить статус миграций
php artisan migrate:status

# Проверить ботов
php artisan bots:check

# Проверить баланс
php artisan balance:check --exchange=okx
```

---

## 🆘 Если что-то пошло не так

### Ошибка: "Table already exists"

```bash
# Удалить все таблицы
php artisan migrate:fresh

# Или вручную через MySQL
mysql -u your_username -p trading_bot -e "DROP DATABASE trading_bot; CREATE DATABASE trading_bot;"
```

### Ошибка: "Foreign key constraint fails"

SQL файл отключает проверку внешних ключей (`SET foreign_key_checks = 0`), так что это не должно быть проблемой.

### Ошибка: "Unknown column 'rsi_period'"

Примените миграцию:
```bash
php artisan migrate
```

---

## 📝 Быстрая команда (все в одном)

```bash
# 1. Импорт
mysql -u your_username -p trading_bot < trading_bot.sql

# 2. Применить недостающие миграции
php artisan migrate

# 3. Проверка
php artisan migrate:status
php artisan tinker
# >>> \App\Models\TradingBot::count();
```

---

## ✅ Готово!

После импорта у вас будет:
- ✅ База данных с данными из SQL файла
- ✅ Все миграции применены
- ✅ Боты готовы к работе
