# Выгрузки OKX (Order History / Trade Details)

Сюда можно складывать CSV-файлы, экспортированные с OKX:

- **Order History** — история ордеров (дата, символ, сторона, статус и т.д.)
- **Trade Details** — детали сделок

Файлы `*.csv` и `*.xlsx` в этой папке не попадают в git (см. `.gitignore`).

Пример: перенести из корня проекта:
```bash
mv "Order History_2025-11-01~2026-02-02~....csv" storage/okx-exports/
mv "Trade Details_2025-11-01~2026-02-02~....csv" storage/okx-exports/
```

Использование: сверка с таблицей `trades` в БД, архив, будущий импорт.
