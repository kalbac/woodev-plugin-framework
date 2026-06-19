# Промт для следующей сессии (s24): реализация клиента подключения аккаунта (MVP + #5)

> Написан в s23 (2026-06-19). Кросс-проектная сессия (в основном framework, 1 строка в коннекторе). Скопируй как стартовый бриф. После выполнения — замени на промт для s25.

---

## ⚠️ Самое первое (do first)

1. **Запушить висящий docs-коммит s23:** `git push origin main` из `D:\Projects\woodev_framework`. Локально на `main` лежат НЕзапушенные коммиты: спек (`7976214`) + session-save s23 (этот промт + CURRENT-STATE + SESSION-LOG). В s23 прямой push в `main` заблокировал auto-mode classifier — нужен ручной пуш.
2. **Прочитать спек:** `docs-internal/specs/2026-06-19-account-connection-client-design.md` — это контракт реализации. Он уже одобрен оператором (scope: **MVP + #5**).
3. **Сверить контракт коннектора с живым кодом** (он мог измениться): `D:\Projects\woodev_theme\plugins\woodev-account-connector\includes\` — особенно `class-signer.php` (payload подписи) и `class-rest-controller.php` (эндпоинты, заголовки, return-параметры). Спек §"Grounding" фиксирует контракт на момент s23.

## Что делаем (срез s23, одобрен оператором)

**MVP хендшейк + connected-состояние (#6/#9) + бейджи «установлен» (#5).** Полный список — в спеке. Кратко:

### Framework (основное)
1. **Фильтруемый store-URL** — фильтр `woodev_account_api_url` (дефолт `https://woodev.ru`, по аналогии с `woodev_license_base_url`). Каталожные `PRODUCTS_URL/CATEGORIES_URL` тоже сделать фильтруемыми (`woodev_extensions_store_url`) — нужно для рига.
2. **`Woodev_Account_Signer`** (`woodev/account/class-account-signer.php`) — чистый HMAC по контракту коннектора: `wp_json_encode([host, request_uri, method(UPPER), body, timestamp])` → `hash_hmac('sha256', …, key)`. **Юнит-тест на байт-точность** против фикстуры (round-trip-страховка против коннекторского `Signer`).
3. **`Woodev_Account_Connection`** (`woodev/account/class-account-connection.php`) — connect/return-хендлеры (на загрузке страницы каталога по query-флагам), обмен токенов, подписанный transport (`/oauth/me`, disconnect), хранение в опции `woodev_account_data`, transient `woodev_account_handshake` (secret между init/return). См. спек «Flow» + «Signing».
4. **REST disconnect** — `POST woodev/v1/account/disconnect` (cap `manage_options` + REST nonce) → подписанный `/oauth/invalidate_token` + чистка опции (best-effort: чистить локально даже при ошибке remote).
5. **Коллектор installed-id (#5)** — из реестра бутстрапа (`Woodev_Plugin_Bootstrap::instance()` → активные плагины → `::instance()` → `get_download_id()`), дедуп int>0. Инжектить в `window.woodevExtensions.installed`.
6. **Bootstrap data** (`class-admin-pages.php::load_plugins_page_scripts`) — добавить `account` (`get_account()` + `connectUrl` + `myAccountUrl`) и `installed`.

### UI (`src/plugins-page/`)
7. **`AccountMenu`** (новый компонент) — #6 (дропдаун «Подключить» + ссылка на my-account) / #9 (аватар + `display_name` + дропдаун «my-account» + «Отключить»). Заменяет нынешнюю плашку аккаунта. Гейт `accountEnabled`.
8. **`ExtensionCard`** — #5: если `installed.includes(product.id)` → бейдж «Установлен» + кнопка «Купить»→«Посмотреть».

### Коннектор (woodev_theme — 1 строка, ты деплоишь)
9. **`/oauth/me` avatar** — в `REST_Controller::me()` добавить `'avatar' => get_avatar_url((int)$connection->user_id)` + юнит-тест. Локальный коммит в outer monorepo (master); деплой на прод — твой шаг.

## Отложено (НЕ в s24)
- **#7** «Мои покупки» (+ коннектор `/purchases` с историей заказов).
- **#8** установка-из-коннектора (+ коннектор `/download/{id}` с EDD SL package URL + `WP_Upgrader`). Security-critical, отдельная сессия.
- **Рейтинг-в-API** (баг woodev_theme, оператор скипнул).

## Подход и гигиена
- `writing-plans` по спеку → атомарные TDD-куски; зелёный `composer check` (668 unit базовая) после каждого; JS-сборка (`npm run build`) перед коммитом (Assets-build-parity).
- **Codex adversarial-review ОБЯЗАТЕЛЕН** на: реконструкцию подписи (`request_uri` pretty/plain-permalink + байт-равенство body + timestamp в payload + constant-time), nonce на connect-return, обращение с secret-transient (TTL/одноразовость/не течёт в логи), auth на disconnect, open-redirect. Re-critic собственных правок. Находки НЕ автофиксить — спрашивать оператора (рекомендованная опция первой).
- **Мердж:** ветка → PR → зелёный CI (вкл. Assets-build-parity) → `--squash --delete-branch`, НЕ `--auto`; ресинк main.
- **Флипнуть `woodev_extensions_account_enabled`** (default false → true) ТОЛЬКО после e2e-проверки хендшейка на риге.
- v2.0.1 НЕ выпущен → новые символы `@since 2.0.2`. Публичные `docs/` — НЕ трогаем (решение s13).

## Риг (e2e — главный критерий приёмки)
- **Issuer (woodev.ru-локально):** woodev_theme wp-env `http://localhost:8090` — `woodev-account-connector` активен, таблица есть, `request_token` отдаёт secret. CLI-контейнер: `c8ec47a5035920b76223df8ef5b79e40-cli-1`.
- **Стенд (consumer):** framework wp-env `http://localhost:8888`, `admin`/`password`. `./woodev` (вкл. `assets/build`) live-mapped — правки PHP/JS сразу видны. CLI-контейнер: `de59f74e6d3d19d18a7f7b6608fda7e7-cli-1`.
- **e2e:** фильтр `woodev_account_api_url` → `:8090`; на стенде жму «Подключить» → редирект на issuer `/oauth/authorize` → логин на issuer → «Подключить» → назад на стенд → вижу имя+аватар. Проверить `woodev_account_data` (eval-file), затем disconnect → опция чистится + строка connection на issuer удалена.
- ⚠️ **SSRF:** запрос идёт **стенд → issuer (localhost:8090)** — убедиться, что `wp_safe_remote_*` стенда пропускает локальный хост issuer (добавить риг-only фильтр, если нет). Issuer-сторона SSRF-bypass уже есть (`env==='local'`).
- Драйв состояний: `docker exec <cli> wp eval-file` через **PowerShell** (Git-Bash манглит `/tmp` MSYS-путь; кириллица/кавычки ломают inline `wp eval`). Файлы класть в gitignored `.wp-env-stand/`, копировать `docker cp`. НЕ `do_action('admin_init')` в wp-cli. Браузер-сессия wp-admin живёт недолго — перелогин `admin`/`password`.
- ⚠️ Сброс кэша каталога (TTL неделя): `wp transient delete woodev_extensions_catalog_v2` на стенде.

## Старт сессии
- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт, спек.
- Контракт коннектора: woodev_theme `plugins/woodev-account-connector/includes/` (читать живой код, спек §Grounding — снимок s23).
