# DOCS.md — Технічний паспорт проєкту `scat-joomla-migrator`

**Версія:** 1.2  
**Назва плагіна:** SCaT Joomla 1.5 to WordPress Content Migrator  
**Автор:** SCaT  
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
  - `ScatMigrator\ImageMigrator` — лише при `is_admin()`.
  - `ScatMigrator\ArchiveBrowser` — лише при `is_admin()`.

---

## 2. Карта файлів (File Map)

| Файл | Призначення |
| :--- | :--- |
| `scat-joomla-migrator.php` | Точка входу, заголовок плагіна, константи, PSR-4 автозавантажувач, ініціалізація модулів. |
| `includes/Updater.php` | Автооновлення плагіна з GitHub (Public & Private репозиторії, захист заголовків, перейменування ZIP). |
| `includes/AdminPage.php` | Сторінка адмінки «📦 Міграція Joomla -> WP»: діагностика середовища, налаштування токена, пакетний конвертер зображень у WebP, підключення ассетів. |
| `includes/ImageMigrator.php` | Рекурсивний сканер архіву Joomla (`joomla-legacy/images/`, `dmdocuments/`), черга `.queue.json` та пакетний GD-конвертер зображень у WebP (`scat-content-webp/`). |
| `includes/ArchiveBrowser.php` | Візуальний провідник «📁 Архів старого сайту» (`Медіафайли -> 📁 Архів старого сайту`) з навігацією по підпапках, пошуком та імпортом файлів у Медіатеку WP в 1 клік. |
| `assets/admin.js` | Клієнтська логіка AJAX-сканування, пакетної конвертації з прогрес-баром та візуального провідника архіву. |
| `assets/admin.css` | Стилізація карток статистики, прогрес-бару, сітки папок і файлів провідника. |
| `AGENTS.md` | Правила роботи для AI-агентів (економія токенів, аудит коду, протокол релізів). |
| `DOCS.md` | Технічний паспорт плагіна та історія змін. |
| `.gitignore` | Виключення `*.sql`, `*.zip`, `.DS_Store`, `Thumbs.db`, `node_modules/`, `.env`. |

---

## 3. Константи та шляхи зберігання

| Константа | Значення / Опис |
| :--- | :--- |
| `SCAT_MIGRATOR_VERSION` | `'1.2'` — поточна версія плагіна. |
| `SCAT_MIGRATOR_FILE` | `__FILE__` — повний шлях до головного файлу плагіна. |
| `SCAT_MIGRATOR_BASENAME` | `plugin_basename(__FILE__)` — слаг базового файлу плагіна. |
| `SCAT_MIGRATOR_DIR` | `plugin_dir_path(__FILE__)` — абсолютний шлях до теки плагіна. |
| `SCAT_MIGRATOR_URL` | `plugin_dir_url(__FILE__)` — URL до теки плагіна. |
| `SCAT_MIGRATOR_GITHUB_TOKEN` | *(опційно, wp-config.php)* Пріоритет №1 для токена GitHub. |
| `SCAT_GITHUB_TOKEN` | *(опційно, wp-config.php)* Пріоритет №2 (спільний токен). |

### Шляхи архіву в `wp_upload_dir()['basedir']`
- `/wp-content/uploads/joomla-legacy/images/` — вихідні зображення Joomla 1.5.
- `/wp-content/uploads/joomla-legacy/dmdocuments/` — вихідні документи та сертифікати DocMan.
- `/wp-content/uploads/scat-content-webp/` — цільова папка сконвертованих WebP-зображень (повторює ієрархію підпапок `joomla-legacy/images/`).
- `/wp-content/uploads/scat-content-webp/.queue.json` — черга відносних шляхів неконвертованих зображень.

---

## 4. Опції БД, Post Meta та Transients

### Опції (`wp_options`)
- `scat_migrator_github_token` — персональний GitHub-токен плагіна (пріоритет №3).
- `scat_github_token` — спільний GitHub-токен (пріоритет №4, сумісність з іншими плагінами SCaT).

### Мета-поля вкладень (`wp_postmeta`)
- `_scat_archive_rel_path` — зберігає джерело та відносний шлях імпортованого файлу з архіву у форматі `<source>:<rel_path>` (наприклад, `webp:stories/news/pic.webp` або `docs:cert.pdf`) для захисту від дублікатів та групової перевірки статусу в провіднику.

### Transients
- `scat_migrator_remote_version` — кеш віддаленої версії (TTL: `12 * HOUR_IN_SECONDS`).
- `scat_migrator_update_error` — кеш мережевих/API помилок (TTL: `10 * MINUTE_IN_SECONDS`).
- `scat_migrator_check_notice_<user_id>` — тимчасове повідомлення після ручної перевірки оновлень (TTL: `60` сек).

---

## 5. Хуки та AJAX-ендпоінти WordPress

### `ScatMigrator\Updater`
- `pre_set_site_transient_update_plugins` — виконує HTTP-запит за версією (якщо кеш протух) та оновлює об'єкт transient.
- `site_transient_update_plugins` — **без HTTP-запитів**, лише порівняння кешованої версії з локальною версією з диска через `version_compare()`.
- `plugins_api` — формує картку інформації про плагін у модальному вікні оновлення.
- `http_request_args` (пріоритет `9999`) та `http_api_curl` (пріоритет `9999`, 3 аргументи) — захист заголовків `Authorization` / `Accept` для GitHub API та очищення `Authorization` при 302-редиректі на CDN.
- `upgrader_source_selection` — перейменовує розпаковану теку GitHub ZIP у `scat-joomla-migrator`.
- `upgrader_process_complete` — очищає transient-кеш після завершення оновлення.
- `plugin_row_meta`, `admin_init`, `admin_notices` — ручна перевірка оновлень.

### `ScatMigrator\AdminPage`
- `admin_menu` — реєстрація сторінки верхнього рівня «📦 Міграція Joomla -> WP» (`manage_options`, `dashicons-database-import`).
- `admin_post_scat_migrator_save_token` — збереження токена з перевіркою Nonce та очищенням кешу оновлень.
- `admin_enqueue_scripts` — підключення `assets/admin.css` та `assets/admin.js` строго на сторінках `scat-joomla-migrator` та `scat-joomla-archive`.

### `ScatMigrator\ImageMigrator`
- `wp_ajax_scat_migrator_scan_images` (права `manage_options`) — рекурсивне сканування `joomla-legacy/images/` та `dmdocuments/`, фільтрація битих імен (наприклад, `.jpg`), підрахунок статистики та запис черги `.queue.json`.
- `wp_ajax_scat_migrator_convert_batch` (права `manage_options`) — пакетна конвертація зрізу черги `.queue.json` через GD у формат `.webp` зі збереженням прозорості PNG/GIF та пропорційним масштабуванням до `max_width` (за замовчуванням `1920px`, якість `82`).

### `ScatMigrator\ArchiveBrowser`
- `admin_menu` — реєстрація підменю «Медіафайли -> 📁 Архів старого сайту» (`parent_slug: 'upload.php'`, `menu_slug: 'scat-joomla-archive'`, права `upload_files`).
- `wp_ajax_scat_archive_browse` (права `upload_files`) — навігація по підпапках та рекурсивний пошук у `scat-content-webp` або `dmdocuments` із пагінацією по 60 файлів і груповим SQL-запитом (`$wpdb->prepare()`) для перевірки імпортованих файлів у Медіатеку WP.
- `wp_ajax_scat_archive_import_to_media` (права `upload_files`) — копіювання вибраного файлу в поточну папку завантажень WP (`wp_unique_filename()`), реєстрація attachment (`wp_insert_attachment()`, `wp_generate_attachment_metadata()`) та збереження мета-ключа `_scat_archive_rel_path`.

---

## 6. Історія змін

- **v1.2** — Реалізовано `ImageMigrator` (сканер архіву Joomla та пакетний GD-конвертер зображень у WebP з AJAX прогрес-баром) та `ArchiveBrowser` (візуальний провідник «📁 Архів старого сайту» в розділі «Медіафайли» з пошуком, навігацією та імпортом у Медіатеку WP в 1 клік).
- **v1.1** — Тестове підвищення версії для перевірки системи автооновлення з GitHub; додано заголовок `Author: SCaT`.
- **v1.0** — Початковий каркас плагіна: PSR-4 автозавантажувач, `Updater.php` (GitHub Public/Private автооновлення), `AdminPage.php` (діагностика середовища, налаштування токена, заглушка Етапу 2), документація `AGENTS.md` та `DOCS.md`.
