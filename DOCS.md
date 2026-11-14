# DOCS.md — Технічний паспорт проєкту `scat-joomla-migrator`

**Версія:** 1.0  
**Назва плагіна:** SCaT Joomla 1.5 to WordPress Content Migrator  
**Репозиторій:** `piratexnik/scat-joomla-migrator` (гілка `main`)  
**Мінімальні вимоги:** PHP 8.1+, WordPress 6.5+

---

## 1. Архітектура

Плагін побудовано за стандартом **PSR-4**:
- Кореневий простір імен: `ScatMigrator\` -> директорія `includes/`.
- Автозавантаження реалізовано через `spl_autoload_register` у файлі `scat-joomla-migrator.php`.
- У кожному PHP-файлі увімкнено `declare(strict_types=1);` та перевірку `defined('ABSPATH') || exit;`.
- Ініціалізація модулів виконується на хуку `plugins_loaded`:
  - `ScatMigrator\Updater` — лише при `is_admin() || wp_doing_cron()`.
  - `ScatMigrator\AdminPage` — лише при `is_admin()`.

---

## 2. Карта файлів (File Map)

| Файл | Призначення |
| :--- | :--- |
| `scat-joomla-migrator.php` | Точка входу, заголовок плагіна, константи, PSR-4 автозавантажувач, ініціалізація модулів. |
| `includes/Updater.php` | Автооновлення плагіна з GitHub (Public & Private репозиторії, захист заголовків, перейменування ZIP). |
| `includes/AdminPage.php` | Сторінка адмінки «📦 Міграція Joomla -> WP»: діагностика середовища, налаштування токена, заглушка Етапу 2. |
| `AGENTS.md` | Правила роботи для AI-агентів (економія токенів, аудит коду, протокол релізів). |
| `DOCS.md` | Технічний паспорт плагіна та історія змін. |
| `.gitignore` | Виключення `*.sql`, `*.zip`, `.DS_Store`, `Thumbs.db`, `node_modules/`, `.env`. |

---

## 3. Константи

| Константа | Значення / Опис |
| :--- | :--- |
| `SCAT_MIGRATOR_VERSION` | `'1.0'` — поточна версія плагіна. |
| `SCAT_MIGRATOR_FILE` | `__FILE__` — повний шлях до головного файлу плагіна. |
| `SCAT_MIGRATOR_BASENAME` | `plugin_basename(__FILE__)` — слаг базового файлу плагіна. |
| `SCAT_MIGRATOR_DIR` | `plugin_dir_path(__FILE__)` — абсолютний шлях до теки плагіна. |
| `SCAT_MIGRATOR_URL` | `plugin_dir_url(__FILE__)` — URL до теки плагіна. |
| `SCAT_MIGRATOR_GITHUB_TOKEN` | *(опційно, wp-config.php)* Пріоритет №1 для токена GitHub. |
| `SCAT_GITHUB_TOKEN` | *(опційно, wp-config.php)* Пріоритет №2 (спільний токен). |

---

## 4. Опції БД та Transients

### Опції (`wp_options`)
- `scat_migrator_github_token` — персональний GitHub-токен плагіна (пріоритет №3).
- `scat_github_token` — спільний GitHub-токен (пріоритет №4, сумісність з іншими плагінами SCaT).

### Transients
- `scat_migrator_remote_version` — кеш віддаленої версії (TTL: `12 * HOUR_IN_SECONDS`).
- `scat_migrator_update_error` — кеш мережевих/API помилок (TTL: `10 * MINUTE_IN_SECONDS`).
- `scat_migrator_check_notice_<user_id>` — тимчасове повідомлення після ручної перевірки оновлень (TTL: `60` сек).

---

## 5. Хуки WordPress

### `ScatMigrator\Updater`
- `pre_set_site_transient_update_plugins` — виконує HTTP-запит за версією (якщо кеш протух) та оновлює об'єкт transient.
- `site_transient_update_plugins` — **без HTTP-запитів**, лише порівняння кешованої версії з локальною версією з диска через `version_compare()`.
- `plugins_api` — формує картку інформації про плагін у модальному вікні оновлення.
- `http_request_args` (пріоритет `9999`) — додає `Authorization` / `Accept` для `api.github.com/repos/piratexnik/scat-joomla-migrator/` і прибирає `Authorization` для `codeload.github.com` та `objects.githubusercontent.com`.
- `http_api_curl` (пріоритет `9999`, 3 аргументи) — дублює захист заголовків на рівні cURL (`CURLOPT_HTTPHEADER`).
- `upgrader_source_selection` — перейменовує розпаковану теку GitHub ZIP у `scat-joomla-migrator` через `$wp_filesystem->move()`.
- `upgrader_process_complete` — очищає transient-кеш після завершення оновлення.
- `plugin_row_meta` — додає посилання «🔄 Проверить обновления» (з Nonce та перевіркою `manage_options`).
- `admin_init` — обробник ручної перевірки оновлень.
- `admin_notices` — виведення результату ручної перевірки.

### `ScatMigrator\AdminPage`
- `admin_menu` — реєстрація сторінки верхнього рівня «📦 Міграція Joomla -> WP» (`manage_options`, `dashicons-database-import`).
- `admin_post_scat_migrator_save_token` — збереження токена з перевіркою Nonce, `current_user_can('manage_options')`, `wp_unslash()`, `sanitize_text_field()` та скиданням кешу оновлень.

---

## 6. Алгоритм роботи `Updater.php`

1. **Читання локальної версії:**
   - `Updater::get_local_version()` читає перші 4 КБ файлу `SCAT_MIGRATOR_FILE` напряму з диска через `file_get_contents()` і парсить заголовок `Version:`, оминаючи кеш `get_plugin_data()` та константи в RAM.
2. **Визначення режиму (Public / Private):**
   - Пошук токена: `SCAT_MIGRATOR_GITHUB_TOKEN` -> `SCAT_GITHUB_TOKEN` -> `scat_migrator_github_token` -> `scat_github_token`.
   - **Public (токен відсутній):**
     - Версія: `https://raw.githubusercontent.com/piratexnik/scat-joomla-migrator/main/scat-joomla-migrator.php?t=<timestamp>`.
     - Пакет: `https://github.com/piratexnik/scat-joomla-migrator/archive/refs/heads/main.zip`.
   - **Private (токен заданий):**
     - Версія: `https://api.github.com/repos/piratexnik/scat-joomla-migrator/contents/scat-joomla-migrator.php?ref=main` з заголовками `Authorization: Bearer <token>`, `Accept: application/vnd.github+json`, `User-Agent: WordPress-ScatMigrator`, декодуванням `base64_decode($json['content'])` та парсингом `Version:`.
     - Пакет: `https://api.github.com/repos/piratexnik/scat-joomla-migrator/zipball/main`.
3. **Захист від 400 Bad Request при 302-редиректі:**
   - При скачуванні ZIP через GitHub API відбувається 302-редирект на `codeload.github.com` або `objects.githubusercontent.com`.
   - Хуки `http_request_args` та `http_api_curl` гарантують передачу `Authorization` лише на API-ендпоінт репозиторію та видалення `Authorization` при зверненні до CDN-хостів.

---

## 7. Історія змін

- **v1.0** — Початковий каркас плагіна: PSR-4 автозавантажувач, `Updater.php` (GitHub Public/Private автооновлення), `AdminPage.php` (діагностика середовища, налаштування токена, заглушка Етапу 2), документація `AGENTS.md` та `DOCS.md`.
