# 📊 Как посмотреть таблицы базы данных в Cursor

## Способ 1: Расширение для SQLite (РЕКОМЕНДУЕТСЯ)

### Установите расширение:

1. **Cmd+Shift+X** (открыть расширения)
2. Найдите одно из:
   - **SQLite Viewer** (автор: qwtel)
   - **SQLite** (автор: alexcvzz)
   - **Database Client** (автор: cweijan) - универсальное для разных БД
3. Нажмите **Install**

### Использование:

1. Откройте файл `database/database.sqlite`
2. Расширение автоматически покажет структуру БД
3. Или нажмите правой кнопкой на файл → **Open Database**

---

## Способ 2: Через терминал (Laravel Tinker)

### Просмотр всех таблиц:

```bash
php artisan tinker
```

Затем в tinker:
```php
DB::select("SELECT name FROM sqlite_master WHERE type='table'");
```

Или через Schema:
```php
Schema::getTableListing();
```

### Просмотр структуры таблицы:

```php
Schema::getColumnListing('users');
Schema::getColumnListing('trading_bots');
Schema::getColumnListing('trades');
Schema::getColumnListing('exchange_accounts');
```

---

## Способ 3: SQL команды через artisan

### Создайте команду для просмотра таблиц:

Выполните в терминале:
```bash
php artisan db:table users
php artisan db:table trading_bots
php artisan db:table trades
```

Или используйте SQL напрямую:
```bash
php artisan tinker
```

Затем:
```php
DB::table('users')->get();
DB::table('trading_bots')->get();
DB::table('trades')->get();
DB::table('exchange_accounts')->get();
```

---

## Способ 4: Расширение Database Client (универсальное)

### Установите:

1. **Cmd+Shift+X**
2. Найдите: **Database Client** (автор: cweijan)
3. Установите

### Настройка:

1. **Cmd+Shift+P** → `Database Client: Add Connection`
2. Выберите **SQLite**
3. Укажите путь: `database/database.sqlite`
4. Подключитесь

Теперь вы сможете:
- Просматривать все таблицы
- Выполнять SQL запросы
- Редактировать данные
- Видеть структуру таблиц

---

## Способ 5: Через команду Laravel

### Просмотр всех таблиц одной командой:

Создайте временную команду или используйте tinker:

```bash
php artisan tinker
```

```php
// Все таблицы
collect(DB::select("SELECT name FROM sqlite_master WHERE type='table'"))->pluck('name');

// Структура конкретной таблицы
DB::select("PRAGMA table_info(users)");
DB::select("PRAGMA table_info(trading_bots)");
DB::select("PRAGMA table_info(trades)");
```

---

## Способ 6: Внешние инструменты

### SQLite Browser (отдельное приложение):

1. Установите **DB Browser for SQLite** (бесплатно)
   - Скачайте: https://sqlitebrowser.org/
2. Откройте файл `database/database.sqlite`
3. Просматривайте таблицы, выполняйте запросы

---

## 🎯 Быстрый способ (рекомендую)

1. **Установите расширение**: **Database Client** (cweijan)
2. **Cmd+Shift+P** → `Database Client: Add Connection`
3. Выберите **SQLite**
4. Путь: `database/database.sqlite`
5. Готово! Теперь видите все таблицы в боковой панели

---

## 📋 Список таблиц в вашем проекте

Судя по миграциям, у вас должны быть:

- `users` - пользователи
- `exchange_accounts` - аккаунты бирж
- `trading_bots` - торговые боты
- `trades` - сделки
- `cache` - кэш
- `cache_locks` - блокировки кэша
- `jobs` - очереди
- `job_batches` - батчи очередей
- `failed_jobs` - неудачные задачи
- `sessions` - сессии

---

## 💡 Полезные SQL запросы

После подключения к БД можете выполнять:

```sql
-- Все таблицы
SELECT name FROM sqlite_master WHERE type='table';

-- Структура таблицы
PRAGMA table_info(trading_bots);

-- Данные из таблицы
SELECT * FROM trading_bots;
SELECT * FROM trades ORDER BY created_at DESC LIMIT 10;
SELECT * FROM users;

-- Количество записей
SELECT COUNT(*) FROM trades;
SELECT COUNT(*) FROM trading_bots WHERE is_active = 1;
```
