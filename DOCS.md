# DOCS.md — Технічний паспорт проєкту `scat-joomla-migrator`

**Версія:** 1.7  
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
  - `ScatMigrator\ContentMigrator` — у `is_admin()` реєструє AJAX-ендпоінти міграції, а на фронтенді (`wp_footer`) підключає легкий лайтбокс для мігрованих галерей `.scat-gallery-item`.

---

## 2. Карта файлів (File Map)

| Файл | Призначення |
| :--- | :--- |
| `scat-joomla-migrator.php` | Точка входу, заголовок плагіна, константи, PSR-4 автозавантажувач, ініціалізація модулів. |
| `includes/Updater.php` | Автооновлення плагіна з GitHub (Public & Private репозиторії, захист заголовків, перейменування ZIP). |
| `includes/AdminPage.php` | Повноширинна сторінка адмінки «📦 Міграція Joomla -> WP»: діагностика, токен, конвертер WebP, аналізатор БД, модальне вікно перегляду статей, 3-мовне створення рубрик, імпорт та пакетний AI-допереклад. |
| `includes/ImageMigrator.php` | Рекурсивний сканер архіву Joomla (`joomla-legacy/images/`, `dmdocuments/`), черга `.queue.json` та пакетний GD-конвертер зображень у WebP (`scat-content-webp/`). |
| `includes/ArchiveBrowser.php` | Візуальний провідник «📁 Архів старого сайту» (`Медіафайли -> 📁 Архів старого сайту`) з навігацією по підпапках, пошуком та імпортом файлів у Медіатеку WP в 1 клік. |
| `includes/DatabaseAnalyzer.php` | Потоковий парсер SQL-дампа (`fopen`/`fgets`), мапінг мов `jos_languages`, групуючий аналіз категорій з українськими назвами та розбивкою перекладів UK/EN, AJAX-передперегляд статей категорії. |
| `includes/ContentMigrator.php` | Пакетний мігратор статей `jos_content` та `jos_jf_content` у WordPress (гібридний режим `uk_full_plus_existing`, розгортання `{yoogallery src=[...]}` в адаптивні 4:3 WebP-галереї з лайтбоксом та автообкладинкою, оновлення вже перенесених записів при `skip_existing = false`, Rank Math SEO, пакетний AI-допереклад через `plu_translate_dictionary` із захистом галерей плейсхолдерами `[[SCAT_GALLERY_X]]`). |
| `assets/admin.js` | Клієнтська логіка AJAX-сканування, пакетної конвертації WebP, завантаження/аналізу SQL, модального вікна вибору статей, пакетного імпорту, пакетного AI-доперекладу та візуального провідника архіву. |
| `assets/admin.css` | Повноширинна (`100%`) стилізація карток, таблиці категорій Joomla, модального вікна перегляду статей, 3-мовного конструктора рубрик, логу імпорту та провідника архіву. |
| `AGENTS.md` | Правила роботи для AI-агентів (економія токенів, аудит коду, протокол релізів). |
| `DOCS.md` | Технічний паспорт плагіна та історія змін. |
| `.gitignore` | Виключення `*.sql`, `*.zip`, `.DS_Store`, `Thumbs.db`, `node_modules/`, `.env`. |

---

## 3. Константи та шляхи зберігання

| Константа | Значення / Опис |
| :--- | :--- |
| `SCAT_MIGRATOR_VERSION` | `'1.7'` — поточна версія плагіна. |
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
- `_joomla_content_lang` — код мови перенесеної версії запису (`uk`, `ru`, `en`).
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
- `wp_ajax_scat_migrator_analyze_db` (`manage_options`) — групуючий аналіз категорій, статей, діапазонів дат, українських назв розділів/категорій та розбивки перекладів Joom!Fish (`UK` / `EN`).
- `wp_ajax_scat_migrator_preview_category` (`manage_options`) — отримання списку всіх статей вибраної категорії для модального вікна передперегляду та поштучного вибору.

### `ScatMigrator\ContentMigrator`
- `wp_ajax_scat_migrator_create_wp_cat` (`manage_options`) — одночасне створення та зв'язування 3 мовних версій рубрики WordPress (`UK + RU + EN`) через `pll_set_term_language()` та `pll_save_term_translations()`.
- `wp_ajax_scat_migrator_import_articles_batch` (`manage_options`) — пакетне перенесення або оновлення вибраних категорій/статей у WordPress із підтримкою гібридного режиму `uk_full_plus_existing`, автоматичним розгортанням `{yoogallery src=[...]}` у WebP-галереї та автообкладинкою з першого фото галереї.
- `wp_ajax_scat_migrator_ai_translate_recent` (`manage_options`) — пакетний AI-допереклад останніх N найсвіжіших записів вибраної UK-рубрики (до 15 статей за 1 AI-запит `plu_translate_dictionary`) із заміною галерей на плейсхолдери `[[SCAT_GALLERY_X]]`, виключенням порожнього `content`/`excerpt` у фотозвітах та миттєвим пропуском вже перекладених записів.
- `wp_footer` — легкий фронтенд-лайтбокс зі стрілками Вліво/Вправо, клавіатурною навігацією та закриттям по `Esc`/фону для `.scat-gallery-item`.

---

## 6. Історія змін

- **v1.7** — Оптимізовано AI-допереклад у Блоці 2.4 для економії квот і токенів API: додано вирізання та відновлення блоків `.scat-migrated-gallery` через плейсхолдери `[[SCAT_GALLERY_X]]`, відправку лише заголовка `title` для фотозвітів без тексту, швидку передфільтрацію вже перекладених записів без холостих AJAX-кроків та пакетне пакування декількох статей (`ai_batch_size`, за замовчуванням 5) в один виклик `plu_translate_dictionary()`.
- **v1.6** — Реалізовано автоматичний парсинг та розгортання тегів `{yoogallery src=[...] ...}` із регістронезалежним пошуком папок у `scat-content-webp` (`resolve_case_insensitive_dir`), фільтрацією `thumbs/` та `thumb_*`, натуральним сортуванням `natsort`, генерацією самодостатньої 4:3 CSS-grid галереї з лайтбоксом, автоматичним встановленням першого фото галереї як Обкладинки запису (Featured Image) для всіх мовних версій (`UK`, `RU`, `EN`), а також коректним оновленням вже імпортованих записів без дублікатів при `skip_existing = false`.
- **v1.5** — Розтягнуто інтерфейс та таблицю категорій на 100% ширини екрана; реалізовано створення та зв'язування одразу 3 мовних рубрик (`UK + RU + EN`) в 1 клік; додано гібридний режим імпорту за замовчуванням `uk_full_plus_existing` (головний UK-запис для 100% статей + збереження та прив'язка існуючих RU/EN перекладів); додано блок 2.4 для пакетного AI-доперекладу останніх N свіжих записів через `plu_translate_dictionary` (Polyland-Up).
- **v1.4** — Додано підтримку режимів перекладів Joom!Fish, автовизначення мов із `jos_languages`, відображення українських назв розділів/категорій і розбивки `UK / EN`, модальне вікно передперегляду та поштучного вибору статей категорії («👁️ Статті»), а також швидкі підказки-чіпси рубрик.
- **v1.3** — Етап 2: реалізовано `DatabaseAnalyzer` та `ContentMigrator`.
- **v1.2** — Реалізовано `ImageMigrator` та `ArchiveBrowser`.
- **v1.1** — Тестове підвищення версії для перевірки системи автооновлення з GitHub; додано заголовок `Author: SCaT`.
- **v1.0** — Початковий каркас плагіна: PSR-4 автозавантажувач, `Updater.php`, `AdminPage.php`, документація `AGENTS.md` та `DOCS.md`.
