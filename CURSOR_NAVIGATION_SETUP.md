# 🔍 Настройка навигации по классам в Cursor (VS Code)

## 📦 Установка необходимых расширений

### 1. Установите PHP расширения

В Cursor откройте расширения (Cmd+Shift+X) и установите:

**Обязательно:**
- ✅ **PHP Intelephense** (автор: Ben Mewburn)
  - Это основное расширение для навигации по PHP коду
  - Поддерживает автозагрузку Composer
  - Дает автодополнение, навигацию, рефакторинг

**Дополнительно (опционально):**
- **PHP Debug** (Xdebug) - для отладки
- **Laravel Extra Intellisense** - для Laravel (если есть)

### 2. После установки

1. Перезапустите Cursor (Cmd+Q и откройте заново)
2. Дождитесь индексации проекта (внизу справа будет прогресс)

---

## ⚙️ Настройка автозагрузки

### 1. Создайте/обновите `.vscode/settings.json`

Создайте файл `.vscode/settings.json` в корне проекта:

```json
{
    "php.suggest.basic": false,
    "intelephense.files.maxSize": 5000000,
    "intelephense.completion.fullyQualifyGlobalConstantsAndFunctions": false,
    "intelephense.environment.includePaths": [
        "vendor"
    ],
    "intelephense.files.exclude": [
        "**/node_modules/**",
        "**/bower_components/**",
        "**/vendor/**/tests/**"
    ],
    "intelephense.stubs": [
        "apache",
        "bcmath",
        "bz2",
        "calendar",
        "Core",
        "ctype",
        "curl",
        "date",
        "dba",
        "dom",
        "enchant",
        "exif",
        "FFI",
        "fileinfo",
        "filter",
        "fpm",
        "ftp",
        "gd",
        "gettext",
        "gmp",
        "hash",
        "iconv",
        "imap",
        "intl",
        "json",
        "ldap",
        "libxml",
        "mbstring",
        "meta",
        "mysqli",
        "oci8",
        "odbc",
        "openssl",
        "pcntl",
        "pcre",
        "PDO",
        "pdo_ibm",
        "pdo_mysql",
        "pdo_pgsql",
        "pdo_sqlite",
        "pgsql",
        "Phar",
        "posix",
        "pspell",
        "random",
        "readline",
        "Reflection",
        "session",
        "shmop",
        "SimpleXML",
        "snmp",
        "soap",
        "sockets",
        "sodium",
        "SPL",
        "sqlite3",
        "standard",
        "superglobals",
        "sysvmsg",
        "sysvsem",
        "sysvshm",
        "tidy",
        "tokenizer",
        "xml",
        "xmlreader",
        "xmlrpc",
        "xmlwriter",
        "xsl",
        "Zend OPcache",
        "zip",
        "zlib"
    ]
}
```

### 2. Обновите автозагрузку Composer

Выполните в терминале:

```bash
cd /Users/iskandarsadykov/PhpstormProjects/bybit
composer dump-autoload
```

---

## 🎯 Использование навигации

После установки расширений:

### Переход к определению:
- **Cmd+Click** (Mac) на класс/метод
- Или **F12** (когда курсор на классе/методе)
- Или **Cmd+F12** - переход к определению

### Найти все использования:
- **Shift+F12** - найти все использования символа
- **Cmd+Shift+F** - поиск по проекту

### Автодополнение:
- Просто начните печатать имя класса
- **Ctrl+Space** - принудительно показать подсказки

### Быстрая навигация:
- **Cmd+P** - быстрый поиск файлов
- **Cmd+Shift+O** - поиск символов в файле
- **Cmd+T** - поиск символов по проекту

---

## 🔧 Если навигация не работает

### 1. Перезапустите языковой сервер

1. Нажмите **Cmd+Shift+P** (Command Palette)
2. Введите: `PHP Intelephense: Restart`
3. Выберите команду

### 2. Проверьте статус индексации

1. Откройте **Output** (View → Output или Cmd+Shift+U)
2. Выберите в выпадающем списке: **PHP Intelephense**
3. Посмотрите, есть ли ошибки

### 3. Очистите кэш Intelephense

1. **Cmd+Shift+P**
2. Введите: `PHP Intelephense: Clear Cache and Reload`
3. Выберите команду

### 4. Проверьте путь к PHP

1. **Cmd+Shift+P**
2. Введите: `Preferences: Open Settings (JSON)`
3. Добавьте (если нет):
```json
{
    "php.validate.executablePath": "/usr/bin/php"
}
```

Или найдите путь к PHP:
```bash
which php
```

---

## ✅ Проверка работы

1. Откройте файл `app/Console/Commands/CreateAdminUserCommand.php`
2. Найдите строку с `User::firstOrCreate`
3. Нажмите **Cmd+Click** на `User`
4. Должен открыться файл `app/Models/User.php`

Если не работает:
- Убедитесь, что расширение PHP Intelephense установлено
- Перезапустите Cursor
- Выполните `composer dump-autoload`

---

## 📝 Быстрая команда для обновления автозагрузки

Создайте файл `.vscode/tasks.json`:

```json
{
    "version": "2.0.0",
    "tasks": [
        {
            "label": "Composer: Dump Autoload",
            "type": "shell",
            "command": "composer dump-autoload",
            "group": "build",
            "problemMatcher": []
        }
    ]
}
```

Затем можно запускать через: **Cmd+Shift+P** → `Tasks: Run Task` → `Composer: Dump Autoload`
