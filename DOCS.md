# DOCS.md — Технічний паспорт проєкту `scat-joomla-migrator`

**Версія:** 1.16  
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
  - `ScatMigrator\ContentMigrator` — у `is_admin()` реєструє AJAX-ендпоінти міграції та оновлення існуючих записів, а на фронтенді (`wp_footer`) підключає легкий лайтбокс для мігрованих галерей `.scat-gallery-item`.
  - `ScatMigrator\ProductHubGenerator` — у `is_admin()` реєструє AJAX-ендпоінти генерації 4 зведених сторінок Продукції (UK + RU + EN), а на фронтенді (`wp_footer`) підключає стилі та JS вкладок/акордеонів/фільтрації для сторінок із `.scat-product-hub`.

---

## 2. Карта файлів (File Map)

| Файл | Призначення |
| :--- | :--- |
| `scat-joomla-migrator.php` | Точка входу, заголовок плагіна, константи, PSR-4 автозавантажувач, ініціалізація модулів. |
| `includes/Updater.php` | Автооновлення плагіна з GitHub (Public & Private репозиторії, захист заголовків, перейменування ZIP). |
| `includes/AdminPage.php` | Повноширинна сторінка адмінки «📦 Міграція Joomla -> WP»: діагностика, токен, конвертер WebP, аналізатор БД, кнопка оновлення існуючих записів WP (DocMan + фото-ряди), модальне вікно перегляду статей, 3-мовне створення рубрик, вибір статусу (`publish`/`draft`), пакетний імпорт та блок «Етап 3: Генератор 4 зведених сторінок Продукції». |
| `includes/ImageMigrator.php` | Рекурсивний сканер архіву Joomla (`joomla-legacy/images/`, `dmdocuments/`), черга `.queue.json` та пакетний GD-конвертер зображень у WebP (`scat-content-webp/`). |
| `includes/ArchiveBrowser.php` | Візуальний провідник «📁 Архів старого сайту» (`Медіафайли -> 📁 Архів старого сайту`) з навігацією по підпапках, пошуком та імпортом файлів у Медіатеку WP в 1 клік. |
| `includes/DatabaseAnalyzer.php` | Потоковий парсер SQL-дампа (`fopen`/`fgets`), мапінг мов `jos_languages`, групуючий аналіз категорій з українськими назвами та розбивкою перекладів UK/EN, AJAX-передперегляд статей категорії. |
| `includes/ContentMigrator.php` | Пакетний мігратор статей `jos_content` та `jos_jf_content` у WordPress (гібридний режим `uk_full_plus_existing`, розгортання `{yoogallery src=[...]}` в адаптивні 4:3 WebP-галереї, заміна посилань DocMan `?option=com_docman&gid=X` за `jos_docman` з гарантованим фоллбеком та `dmdocuments/`, пакування послідовностей `<img>` / `<a><img></a>`, `<div><img></div>` і одиночних фото у `<li>` та після `<ul>`/`<ol>` в компактні фото-ряди `.scat-inline-thumbs` з іконкою лупи та лайтбоксом, безпечна обробка блоків Divi 5 / Gutenberg через `parse_blocks()`/`serialize_blocks()`, масове 1-клік оновлення всіх записів WP, інтеграція з `\PolylangUp\Modules\SameSlug::$forced_lang`, дбайливе зв'язування 3-мовних рубрик Polylang, оновлення записів при `skip_existing = false`, Rank Math SEO). |
| `includes/ProductHubGenerator.php` | Генератор 4 мультимовних зведених сторінок Продукції (`metal-cable-trays`, `ladder-cable-trays`, `metal-hose-sprut`, `mounting-systems`) на UK/RU/EN з інтерактивними вкладками та `#якорями`, картками-акордеонами виробів, пакетним завантаженням Joom!Fish, захищеним очищенням таблиць від колонки кошторису через `DOMDocument` + regex-фоллбек та фронтенд-активами. |
| `assets/admin.js` | Клієнтська логіка AJAX-сканування, пакетної конвертації WebP, завантаження/аналізу SQL, масового оновлення існуючих записів WP, модального вікна вибору статей, пакетного імпорту, покрокової генерації зведених сторінок Продукції (UK -> RU -> EN) із детальною діагностикою HTTP-помилок та візуального провідника архіву. |
| `assets/admin.css` | Повноширинна (`100%`) стилізація карток, таблиці категорій Joomla, модального вікна перегляду статей, 3-мовного конструктора рубрик, логу імпорту, панелі генератора сторінок Продукції та провідника архіву. |
| `AGENTS.md` | Правила роботи для AI-агентів (економія токенів, аудит коду, протокол релізів). |
| `DOCS.md` | Технічний паспорт плагіна та історія змін. |
| `.gitignore` | Виключення `*.sql`, `*.zip`, `.DS_Store`, `Thumbs.db`, `node_modules/`, `.env`. |

---

## 3. Константи та шляхи зберігання

| Константа | Значення / Опис |
| :--- | :--- |
| `SCAT_MIGRATOR_VERSION` | `'1.13'` — поточна версія плагіна. |
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
- `wp_ajax_scat_migrator_create_wp_cat` (`manage_options`) — дбайливий пошук або створення та зв'язування 3 мовних версій рубрики WordPress (`UK + RU + EN`) із підтримкою `\PolylangUp\Modules\SameSlug::$forced_lang`, `pll_set_term_language()` та `pll_save_term_translations()`.
- `wp_ajax_scat_migrator_import_articles_batch` (`manage_options`) — пакетне перенесення або оновлення вибраних категорій/статей у WordPress із підтримкою гібридного режиму `uk_full_plus_existing`, єдиних слагов через `SameSlug::$forced_lang`, автоматичним розгортанням `{yoogallery src=[...]}` у WebP-галереї, заміною посилань DocMan, пакуванням вбудованих фото-рядів та автообкладинкою з першого фото галереї.
- `wp_ajax_scat_migrator_search_wp_posts` (`manage_options`) — швидкий AJAX-пошук записів і сторінок WordPress (`post`, `page`) за `ID`, заголовком або slug для точкового вибору в Блоці 2.1.
- `wp_ajax_scat_migrator_fix_existing_posts` (`manage_options`) — пакетне або точкове (для вибраного запису/сторінки та його перекладів Polylang) оновлення записів і сторінок WordPress (`post`, `page`) на всіх мовах (UK / RU / EN), включно зі сторінками у форматі блоків Divi 5 / Gutenberg (`process_post_content_safely()` через `parse_blocks()`/`serialize_blocks()`): розпакування вкладених галерей-«матрьошок» із суворою ідемпотентністю, перезапис залишкових шляхів `images/...` у WebP (та посилань «Скачати» на оригінали з `target="_blank"`), заміна динамічних посилань `?option=com_docman&...&gid=X`, `dmdocuments/` та документів (`.pdf`, `.doc`, `.docx`, `.rar`, `.zip`, `.dwg`), оформлення табличних бланків у `<td>` (`scat-table-cert-item`), а також пакування послідовностей `<img>` у компактні фото-ряди `.scat-inline-thumbs`.
- `wp_footer` — легкий фронтенд-лайтбокс зі стрілками Вліво/Вправо, клавіатурною навігацією та закриттям по `Esc`/фону для `.scat-gallery-item` та `.scat-table-cert-item` (зі стилями для бланків у таблицях `<td>`).

### `ScatMigrator\ProductHubGenerator`
- `wp_ajax_scat_migrator_hub_status` (`manage_options`) — отримання статусу всіх 4 хаб-сторінок, категорій Joomla та готових `#якорів` для меню WP.
- `wp_ajax_scat_migrator_generate_product_hub` (`manage_options`) — генерація або оновлення 1 хаб-сторінки (підтримує параметр `lang = uk|ru|en|all` для покрокової генерації без таймаутів, `@set_time_limit(300)`, `memory_limit = 512M`, перехоплення `\Throwable` із точною діагностикою).
- `wp_footer` — стилі та JS вкладок (`#якорі` + `hashchange`), фільтрації виробів і карток-акордеонів на сторінках із `.scat-product-hub`.

---

## 6. Історія змін

- **v1.16** — Усунено критичну помилку HTTP 500 при генерації зведених сторінок Продукції (`lotsman-metal-tray` та інших): у `ContentMigrator` методи `combine_intro_and_fulltext()` та `process_article_html()` переведено з `private` у `public` (що спричиняло фатальний `Call to private method`) і додано прапорець `$run_inline_grouping = false` для пропуску зайвого перепакування фото на картках виробів; у `ProductHubGenerator` додано перехоплення `\Throwable` в AJAX-ендпоінтах (`ajax_hub_status`, `ajax_generate_hub`) із виведенням точного тексту PHP-помилки, збільшено ліміти (`set_time_limit(300)`, `memory_limit = 512M`), реалізовано пакетне завантаження перекладів Joom!Fish одним SQL-запитом (`preload_joomfish_for_articles()`) і легкий підрахунок статей для статусу (`COUNT(*) GROUP BY catid`), тимчасове відключення KSES та `wp_targeted_link_rel` на час збереження великих хаб-сторінок у блоці `try/finally`, захисний виклик `scat_get_urls()` через `ReflectionFunction`, безпечне UTF-8 кодування для `DOMDocument` (`mb_encode_numericentity` / `mb_decode_numericentity` + XPath-пошук `#scat-dom-root` без конфліктного `LIBXML_HTML_NOIMPLIED`), обмеження `colspan`/`rowspan` від OOM і гарантований regex-фоллбек `clean_estimate_columns_regex_fallback()`, а також підтримку покрокової генерації по одній мові (`lang = uk|ru|en`); у `assets/admin.js` оновлено `postAjax()` і генератор хабів на послідовні запити UK -> RU -> EN із виведенням реального HTTP-коду та відповіді сервера (до 300 символів) замість загального повідомлення «Мережева помилка».
- **v1.15** — Додано новий модуль `ScatMigrator\ProductHubGenerator` («5. Етап 3: Генератор 4 зведених сторінок Продукції»): автоматичне збирання 4 мультимовних хаб-сторінок WordPress (`metal-cable-trays`, `ladder-cable-trays`, `metal-hose-sprut`, `mounting-systems`) на 3 мовах Polylang (UK + RU + EN) з однаковими слагами (`SameSlug::$forced_lang`), динамічним визначенням `sectionid`/`catid` та вступних статей із `catid = 10`, вкладками за серіями/висотами з `#якорями` для головного меню (автоперехід за `window.location.hash` при завантаженні та `hashchange`), картками-акордеонами виробів із миттєвим пошуком і кнопками «Розгорнути всі / Згорнути всі», клікабельними WebP-кресленнями у лайтбоксі, видаленням старої колонки «Додати до кошторису» з таблиць через `DOMDocument` із збереженням `rowspan`/`colspan`, кнопкою переходу до калькулятора `[scat_app]#catalog` та інтерактивною панеллю в адмінці з копіюванням `#якорів` у 1 клік.
- **v1.14** — Усунено ефект «матрьошки» (повторне вкладене обгортання великих галерей `.scat-migrated-gallery` через ліміт бектрекінгу PCRE): додано лінійний $O(N)$ парсер `unwrap_and_hold_existing_galleries()`, який розпаковує каскадно вкладені обгортки до одного зовнішнього контейнера та ізолює існуючі галереї плейсхолдерами `<!--SCAT_GALLERY_HOLD_X-->` для суворої ідемпотентності (`0` змін на повторному прогоні); покращено обробку табличних сторінок типу «Сертифікати» (`process_and_hold_table_cells()`): зображення в `<td>` не зливаються у `.scat-inline-thumbs`, оформлюються як `.scat-table-cert-item` із лайтбоксом і стилями `max-height: 280px`, кнопки «Скачати» на `images/...` отримують посилання на оригінал + `target="_blank"`, а документи (`.pdf`, `.doc`, `.docx`, `.rar`, `.zip`, `.dwg`) з `dmdocuments/` резолвляться без регістрових помилок; додано точковий вибір запису/сторінки WP з автокомплитом (`scat_migrator_search_wp_posts`), кнопкою скидання `✖`, чекбоксом перекладів Polylang та розширеною діагностикою в лозі.
- **v1.13** — Додано безпечну підтримку сторінок і записів, збережених у конструкторі Divi 5 / Gutenberg (`<!-- wp:divi/... -->` з JSON-атрибутами), через `process_post_content_safely()` із рекурсивним обходом `parse_blocks()` (`attrs`, `innerContent`, `innerHTML`, `innerBlocks`) та зворотною збіркою `serialize_blocks()`; у `get_docman_map()` гарантовано включення всіх непустотних локальних `dmfilename` із `jos_docman` (пріоритет реального регістру на диску + фоллбек на `content_url('uploads/joomla-legacy/dmdocuments/...')`, щоб не залишалося битих посилань `index.php?option=com_docman&gid=X`), а `replace_docman_links()` розширено на всі формати URL включно з порожнім `Itemid=` та подвійним `&amp;amp;`; у `convert_inline_image_groups()` додано підтримку `<div><img></div>`, обгорток `<a><img></a>` та одиночних великих фото (`> 64px` або без малих розмірів) усередині `<li>` та одразу після `<ul>`/`<ol>` (структура сторінки `finished-objects`).
- **v1.12** — Реалізовано автоматичну заміну динамічних посилань Joomla DocMan (`?option=com_docman&...&gid=X` та прямих `dmdocuments/`) за таблицею `jos_docman` із регістронезалежним пошуком реальних файлів у `/wp-content/uploads/joomla-legacy/dmdocuments/` (`get_docman_map()`, `replace_docman_links()`); додано автоматичне пакування груп підрядних `<img>` і зображень всередині `<li>` у компактні однорядкові фото-ряди `.scat-migrated-gallery.scat-inline-thumbs` (`160–180px`) з іконкою лупи та лайтбоксом (`convert_inline_image_groups()`); додано кнопку та AJAX-обробник `scat_migrator_fix_existing_posts` для пакетного 1-клік оновлення всіх існуючих записів і сторінок WP на всіх мовах (UK / RU / EN) без зміни слагов, дат чи зв'язків Polylang.

- **v1.11** — У Блок 2.3 («Налаштування імпорту вибраних категорій / статей») додано вибір статусу створюваних/оновлюваних записів WordPress (`#scat-import-post-status`: `publish` за замовчуванням або `draft`), який передається в `scat_migrator_import_articles_batch` і застосовується у `ContentMigrator` для всіх мовних версій (`UK`, `RU`, `EN`) при `wp_insert_post()` та `wp_update_post()`. Підрахунок «Вже перенесено в WP» у `DatabaseAnalyzer` враховує записи в обох статусах (`publish` та `draft`).
- **v1.10** — Видалено залишковий виклик `populateCategorySelect(selectAiCat, ...)` у `assets/admin.js` (що спричиняв `ReferenceError` та повідомлення «Мережева помилка під час аналізу БД.» після видалення Блока 2.4), додано виведення `err.message` у `.catch()` методу `analyzeDatabase()`, а також додано `filemtime()` до версій `admin.css` та `admin.js` у `AdminPage::enqueue_assets()` для надійного скидання кешу браузера.
- **v1.9** — Повністю видалено Блок 2.4 («Допереклад останніх свіжих новин/статей через Polylang AI» — функціонал перенесено в профільний плагін `Polyland-Up`); інтегровано пряму стиковку з `\PolylangUp\Modules\SameSlug::$forced_lang` для створення єдиних базових слагов статей (`uk`, `ru`, `en`) без суфіксів `-ru`/`-en` і автоматичного очищення застарілих суфіксів (`-ru`, `-en`, `-2`, `-3`) при оновленні (`skip_existing = false`); оновлено `ajax_create_wp_cat()` для дбайливого пошуку існуючих рубрик за назвою/мовою та безпечного зв'язування у Polylang.
- **v1.8** — Виправлено верстку фотогалерей `.scat-migrated-gallery` від впливу `wpautop()` («шахматка» через `<br />`): генерація HTML у `build_yoogallery_block()` переведена в один рядок без `\n`/`\r`, сітка збільшена до `minmax(260px, 1fr)` (`gap: 16px`, `margin: 24px 0`), а у фронтенд-стилях `render_frontend_lightbox()` додано захисні CSS-правила для `.scat-migrated-gallery` (`br`, `p`, `.scat-gallery-item`, `img`), які автоматично виправляють і вже імпортовані записи.
- **v1.7** — Оптимізовано AI-допереклад у Блоці 2.4 для економії квот і токенів API: додано вирізання та відновлення блоків `.scat-migrated-gallery` через плейсхолдери `[[SCAT_GALLERY_X]]`, відправку лише заголовка `title` для фотозвітів без тексту, швидку передфільтрацію вже перекладених записів без холостих AJAX-кроків та пакетне пакування декількох статей (`ai_batch_size`, за замовчуванням 5) в один виклик `plu_translate_dictionary()`.
- **v1.6** — Реалізовано автоматичний парсинг та розгортання тегів `{yoogallery src=[...] ...}` із регістронезалежним пошуком папок у `scat-content-webp` (`resolve_case_insensitive_dir`), фільтрацією `thumbs/` та `thumb_*`, натуральним сортуванням `natsort`, генерацією самодостатньої 4:3 CSS-grid галереї з лайтбоксом, автоматичним встановленням першого фото галереї як Обкладинки запису (Featured Image) для всіх мовних версій (`UK`, `RU`, `EN`), а також коректним оновленням вже імпортованих записів без дублікатів при `skip_existing = false`.
- **v1.5** — Розтягнуто інтерфейс та таблицю категорій на 100% ширини екрана; реалізовано створення та зв'язування одразу 3 мовних рубрик (`UK + RU + EN`) в 1 клік; додано гібридний режим імпорту за замовчуванням `uk_full_plus_existing` (головний UK-запис для 100% статей + збереження та прив'язка існуючих RU/EN перекладів); додано блок 2.4 для пакетного AI-доперекладу останніх N свіжих записів через `plu_translate_dictionary` (Polyland-Up).
- **v1.4** — Додано підтримку режимів перекладів Joom!Fish, автовизначення мов із `jos_languages`, відображення українських назв розділів/категорій і розбивки `UK / EN`, модальне вікно передперегляду та поштучного вибору статей категорії («👁️ Статті»), а також швидкі підказки-чіпси рубрик.
- **v1.3** — Етап 2: реалізовано `DatabaseAnalyzer` та `ContentMigrator`.
- **v1.2** — Реалізовано `ImageMigrator` та `ArchiveBrowser`.
- **v1.1** — Тестове підвищення версії для перевірки системи автооновлення з GitHub; додано заголовок `Author: SCaT`.
- **v1.0** — Початковий каркас плагіна: PSR-4 автозавантажувач, `Updater.php`, `AdminPage.php`, документація `AGENTS.md` та `DOCS.md`.
