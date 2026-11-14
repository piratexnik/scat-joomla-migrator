# DOCS.md — Технічний паспорт проєкту `scat-joomla-migrator`

**Версія:** 1.3  
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
  - `ScatMigrator\DatabaseAnalyzer` — лише при `is_admin()`.
  - `ScatMigrator\ContentMigrator` — лише при `is_admin()`.

---

## 2. Карта файлів (File Map)

| Файл | Призначення |
| :--- | :--- |
| `scat-joomla-migrator.php` | Точка входу, заголовок плагіна, константи, PSR-4 автозавантажувач, ініціалізація модулів. |
| `includes/Updater.php` | Автооновлення плагіна з GitHub (Public & Private репозиторії, захист заголовків, перейменування ZIP). |
| `includes/AdminPage.php` | Сторінка адмінки «📦 Міграція Joomla -> WP»: діагностика середовища, налаштування токена, пакетний конвертер WebP, аналізатор БД та мігратор статей. |
| `includes/ImageMigrator.php` | Рекурсивний сканер архіву Joomla (`joomla-legacy/images/`, `dmdocuments/`), черга `.queue.json` та пакетний GD-конвертер зображень у WebP (`scat-content-webp/`). |
| `includes/ArchiveBrowser.php` | Візуальний провідник «📁 Архів старого сайту» (`Медіафайли -> 📁 Архів старого сайту`) з навігацією по підпапках, пошуком та імпортом файлів у Медіатеку WP в 1 клік. |
| `includes/DatabaseAnalyzer.php` | Потоковий парсер SQL-дампа (`fopen`/`fgets`), імпорт 6 таблиць (`jos_sections`, `jos_categories`, `jos_content`, `jos_languages`, `jos_jf_content`, `jos_docman`) та групуючий аналіз категорій Joomla. |
| `includes/ContentMigrator.php` | Пакетний мігратор статей `jos_content` у WordPress (WebP-заміна `<img>`, посилання `dmdocuments`, уривки до 270 симв., обкладинки без проміжних копій, Rank Math SEO, Polylang, Joom!Fish). |
| `assets/admin.js` | Клієнтська логіка AJAX-сканування, пакетної конвертації WebP, завантаження/аналізу SQL, пакетного імпорту статей та візуального провідника архіву. |
| `assets/admin.css` | Стилізація карток статистики, прогрес-барів, таблиці категорій Joomla, логу імпорту та провідника архіву. |
| `AGENTS.md` | Правила роботи для AI-агентів (економія токенів, аудит коду, протокол релізів). |
| `DOCS.md` | Технічний паспорт плагіна та історія змін. |
| `.gitignore` | Виключення `*.sql`, `*.zip`, `.DS_Store`, `Thumbs.db`, `node_modules/`, `.env`. |

---

## 3. Константи та шляхи зберігання

| Константа | Значення / Опис |
| :--- | :--- |
| `SCAT_MIGRATOR_VERSION` | `'1.3'` — поточна версія плагіна. |
| `SCAT_MIGRATOR_FILE` | `__FILE__` — повний шлях до головного файлу плагіна. |
| `SCAT_MIGRATOR_BASENAME` | `plugin_basename(__FILE__)` — слаг базового файлу плагіна. |
| `SCAT_MIGRATOR_DIR` | `plugin_dir_path(__FILE__)` — абсолютний шлях до теки плагіна. |
| `SCAT_MIGRATOR_URL` | `plugin_dir_url(__FILE__)` — URL до теки плагіна. |
| `SCAT_MIGRATOR_GITHUB_TOKEN` | *(опційно, wp-config.php)* Пріоритет №1 для токена GitHub. |
| `SCAT_GITHUB_TOKEN` | *(опційно, wp-config.php)* Пріоритет №2 (спільний токен). |

### Шляхи архіву в `wp_upload_dir()['basedir']`
- `/wp-content/uploads/joomla-legacy/ya615591_scat2.sql` — SQL-дамп старого сайту Joomla 1.5.
- `/wp-content/uploads/joomla-legacy/images/` — вихідні зображення Joomla 1.5.
- `/wp-content/uploads/joomla-legacy/dmdocuments/` — вихідні документи та сертифікати DocMan.
- `/wp-content/uploads/scat-content-webp/` — цільова папка сконвертованих WebP-зображень.
- `/wp-content/uploads/scat-content-webp/.queue.json` — черга відносних шляхів неконвертованих зображень.

---

## 4. Опції БД, Post Meta та Transients

### Опції (`wp_options`)
- `scat_migrator_github_token` — персональний GitHub-токен плагіна (пріоритет №3).
- `scat_github_token` — спільний GitHub-токен (пріоритет №4, сумісність з іншими плагінами SCaT).

### Мета-поля записів та вкладень (`wp_postmeta`)
- `_scat_archive_rel_path` — джерело та відносний шлях файлу з архіву (`webp:<rel_path>` або `docs:<rel_path>`).
- `_scat_joomla_news_thumb` — прапорець (`1`) для обкладинок новин із `scat-content-webp`, зареєстрованих без нарізки проміжних розмірів.
- `_joomla_content_id` — оригінальний `jos_content.id` для захисту від дублікатів та аудиту міграції.
- `_joomla_cat_id`, `_joomla_section_id`, `_joomla_hits` — збережені атрибути запису Joomla.
- `rank_math_description`, `rank_math_focus_keyword` — перенесені SEO-метадані з `metadesc` та `metakey`.

### Transients
- `scat_migrator_remote_version` — кеш віддаленої версії (TTL: `12 * HOUR_IN_SECONDS`).
- `scat_migrator_update_error` — кеш мережевих/API помилок (TTL: `10 * MINUTE_IN_SECONDS`).
- `scat_migrator_check_notice_<user_id>` — тимчасове повідомлення після ручної перевірки оновлень (TTL: `60` сек).

---

## 5. Хуки та AJAX-ендпоінти WordPress

### `ScatMigrator\Updater`
- `pre_set_site_transient_update_plugins`, `site_transient_update_plugins`, `plugins_api`, `http_request_args`, `http_api_curl`, `upgrader_source_selection`, `upgrader_process_complete`, `plugin_row_meta`, `admin_init`, `admin_notices`.

### `ScatMigrator\AdminPage`
- `admin_menu`, `admin_post_scat_migrator_save_token`, `admin_enqueue_scripts`.

### `ScatMigrator\ImageMigrator`
- `wp_ajax_scat_migrator_scan_images` (`manage_options`) — рекурсивне сканування зображень та документів, запис черги `.queue.json`.
- `wp_ajax_scat_migrator_convert_batch` (`manage_options`) — пакетна GD-конвертація у WebP.

### `ScatMigrator\ArchiveBrowser`
- `wp_ajax_scat_archive_browse` (`upload_files`) — огляд підпапок та пошук у `scat-content-webp` / `dmdocuments`.
- `wp_ajax_scat_archive_import_to_media` (`upload_files`) — імпорт вибраного файлу в Медіатеку WP в 1 клік.

### `ScatMigrator\DatabaseAnalyzer`
- `wp_ajax_scat_migrator_load_sql` (`manage_options`) — потокове читання `.sql` файлу через `fopen()` + `fgets()` та імпорт лише 6 таблиць (`jos_sections`, `jos_categories`, `jos_content`, `jos_languages`, `jos_jf_content`, `jos_docman`).
- `wp_ajax_scat_migrator_drop_tables` (`manage_options`) — видалення тимчасових таблиць `jos_*` із БД WordPress.
- `wp_ajax_scat_migrator_analyze_db` (`manage_options`) — групуючий аналіз категорій, статей, діапазонів дат, перекладів Joom!Fish та вже імпортованих записів у WP.

### `ScatMigrator\ContentMigrator`
- `wp_ajax_scat_migrator_create_wp_cat` (`manage_options`) — створення цільової рубрики «Архів новин» (`archive-news`) з прив'язкою мови Polylang.
- `wp_ajax_scat_migrator_import_articles_batch` (`manage_options`) — пакетне перенесення статей вибраних категорій Joomla у WordPress.

---

## 6. Історія змін

- **v1.3** — Етап 2: реалізовано `DatabaseAnalyzer` (потоковий завантажувач 6 таблиць із SQL-дампа `ya615591_scat2.sql` та інтерактивний аналізатор розділів і категорій Joomla) та `ContentMigrator` (пакетний імпорт статей і новин із заміною зображень на WebP, генерацією уривків до 270 символів, обкладинками без дублювання розмірів, SEO Rank Math та Polylang).
- **v1.2** — Реалізовано `ImageMigrator` (сканер архіву Joomla та пакетний GD-конвертер зображень у WebP з AJAX прогрес-баром) та `ArchiveBrowser` (візуальний провідник «📁 Архів старого сайту» в розділі «Медіафайли» з пошуком, навігацією та імпортом у Медіатеку WP в 1 клік).
- **v1.1** — Тестове підвищення версії для перевірки системи автооновлення з GitHub; додано заголовок `Author: SCaT`.
- **v1.0** — Початковий каркас плагіна: PSR-4 автозавантажувач, `Updater.php` (GitHub Public/Private автооновлення), `AdminPage.php` (діагностика середовища, налаштування токена, заглушка Етапу 2), документація `AGENTS.md` та `DOCS.md`.
