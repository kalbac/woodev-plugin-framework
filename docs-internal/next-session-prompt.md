# Промт для следующей сессии (s25): «Мои покупки» (#7) + бейдж «Куплено»

> Написан в s24 (2026-06-19). Кросс-проектная (в основном framework; коннектор `/purchases` уже есть — возможна 0–1 правка). Скопируй как стартовый бриф. После выполнения — замени на промт для s26.

---

## ⚠️ Самое первое (do first)

1. **Ничего не висит:** s24 полностью смержен и зашипен. Фреймворк: `main` на `ab12ef0` (PR #74), account-UI включён по умолчанию. Коннектор: на проде woodev.ru (оператор задеплоил), локальные коммиты в woodev_theme outer master `47e71b4`+`262a1b4`. Просто синхронизируй `main` (`git pull --ff-only`).
2. **Прочитать контекст s24:** `docs-internal/SESSION-LOG.md` (запись s24) + `docs-internal/CURRENT-STATE.md`. Готчи s24: `rest-endpoint-not-for-browser-cookie-auth`, `wp-nonce-url-esc-html-breaks-js-urls`.
3. **Сверить контракт `/purchases` с живым кодом коннектора** (он мог измениться): `D:\Projects\woodev_theme\plugins\woodev-account-connector\includes\class-purchases.php` + `class-rest-controller.php::purchases()`. На момент s22: подписанный `GET /purchases` → `{purchases: [...]}` через `Purchases::for_customer((int)$connection->customer_id)`. **Зафиксируй фактическую форму элемента** (download_id, название, дата, ссылка на скачивание/лицензию?) — от неё зависит UI.

## Что делаем (#7 — было отложено в s23/s24, одобрено к s25)

**«Мои покупки» + бейдж «Куплено».** Фундамент готов: подписанный transport (`Woodev_Account_Connection::request()`), коннекторный `/purchases`, connected-состояние UI.

### Framework (основное)
1. **Подписанный прокси к `/purchases`.** Транспорт уже есть — `Woodev_Account_Connection::request('GET', '/purchases')` возвращает `array|WP_Error`. Решить, как отдать в React:
   - **Вариант A (как extensions):** REST-маршрут `GET woodev/v1/account/purchases` (cap `manage_options` + REST nonce) → внутри `(new Woodev_Account_Connection())->request('GET','/purchases')` → нормализованный список. React дёргает `apiFetch`. Транзиентный кэш по аналогии с каталогом (короткий TTL — данные пользовательские).
   - **Вариант B:** инжектить `purchases` сразу в `window.woodevExtensions` bootstrap (как `account`/`installed`). Проще, но блокирует рендер страницы на сетевой запрос к issuer — **A предпочтительнее** (ленивая загрузка вкладки).
2. **Нормализатор покупки** (чистый, юнит-тест) — из сырого элемента коннектора в lean-форму для UI: `{ id (download_id, int), title, date, permalink, ... }`. Дедуп по download_id.
3. **Коллектор `purchased`-id** (для бейджа «Куплено») — массив int download_id из ответа `/purchases`. Аналог `Woodev_Installed_Plugins`, но из purchases.

### UI (`src/plugins-page/`)
4. **Вкладка/секция «Мои покупки»** — видна только в connected-состоянии (#9). Ленивая загрузка через `apiFetch('/woodev/v1/account/purchases')`, список купленных плагинов (название, дата, ссылка на woodev.ru). Состояния loading/empty/error по аналогии с каталогом.
5. **Бейдж «Куплено» в `ExtensionCard`** — **отдельно** от готового «Установлен» (#5): если `purchased.includes(product.id)` → бейдж «Куплено» (другой цвет — напр. акцент-cyan, не зелёный installed). Кнопка «Купить» → «Скачать»/«Посмотреть». Приоритет, если товар И куплен И установлен — обсудить с оператором (вероятно «Установлен» важнее).
6. **Bootstrap data** — `purchased` (массив int) для бейджа можно отдать сразу (он дешёвый, если уже тянем purchases на сервере) ИЛИ подтянуть вкладкой и прокинуть в каталог. Решить по UX (бейдж нужен на каталоге сразу → отдать `purchased` в bootstrap, а полную вкладку — лениво).

## Отложено (НЕ в s25)
- **#8** установка-из-коннектора (`WP_Upgrader` + коннектор `/download/{id}` с EDD SL package URL). Security-critical, отдельная сессия.
- **Рейтинг-в-API** (баг woodev_theme, оператор скипнул в s23).

## Подход и гигиена
- `writing-plans` (если объём оправдывает) → атомарные TDD-куски; зелёный `composer check` (**690 unit базовая**) после каждого; JS-сборка (`npm run build`) перед коммитом (Assets-build-parity).
- **Codex-ревью** на: auth прокси (cap + nonce), обращение с токеном/секретом (НЕ течёт в логи `woodev_{plugin_id}_api_request_performed` — клиент идёт мимо `Woodev_API_Base`, проверить), нормализацию ответа issuer (hostile-input/нет фатала на кривом ответе). Re-critic собственных правок. Находки НЕ автофиксить — спрашивать (рекомендованная опция первой).
- **Мердж:** ветка → PR → зелёный CI (вкл. Assets-build-parity) → `--squash --delete-branch`, НЕ `--auto`; ресинк main.
- v2.0.1 НЕ выпущен → новые символы `@since 2.0.2`. Публичные `docs/` — НЕ трогаем (решение s13).
- **Тему woodev-theme НЕ трогать** без явного согласия оператора (s24-урок: login-логику положили в коннектор, тему откатили). Коннектор woodev.ru-специфичен — там EDD-зависимости ок.

## Риг (e2e — главный критерий приёмки)
- **Issuer (woodev.ru-локально):** woodev_theme wp-env `http://localhost:8090`, `woodev-account-connector` активен, EDD `login_page`=20 (`/login` существует), активная тема `woodev-theme`. CLI: `c8ec47a5035920b76223df8ef5b79e40-cli-1`, wordpress: `…-wordpress-1`.
- **Стенд (consumer):** framework wp-env `http://localhost:8888`, `admin`/`password`. `./woodev` (+ `assets/build`) live-mapped (через `woodev-stand` плагин → его `woodev/` смонтирован на главный `./woodev`). CLI: `de59f74e6d3d19d18a7f7b6608fda7e7-cli-1`.
- **Риг-фильтры** (gitignored `.wp-env-stand/woodev-stand.php`): `woodev_account_api_url`/`woodev_extensions_store_url` → `host.docker.internal:8090` (server-to-server), `woodev_account_authorize_url` → `localhost:8090` (browser), `woodev_extensions_account_enabled` → true. Логин гостя дефолтит на issuer `/login` уже из коннектора (тема не нужна).
- **e2e #7:** подключить аккаунт (если не подключён) → открыть «Мои покупки» → увидеть список купленных у issuer-пользователя (нужен EDD-юзер с завершённой покупкой на issuer — возможно засидить); проверить бейдж «Куплено» на совпадающем товаре каталога.
- ⚠️ Драйв состояний: `docker exec <cli> wp eval-file` через **PowerShell** (Git-Bash манглит `/var/www` MSYS-путь → `export MSYS_NO_PATHCONV=1` или PowerShell; кириллица/кавычки ломают inline `wp eval`). `wp eval` НЕ грузит admin-only классы (`Woodev_Admin_Pages`), и admin-only хуки (`load-…`) там не зарегистрированы — это нормально, проверять admin-поведение только в браузере/через `error_log`-инструментацию.
- ⚠️ Сброс кэша каталога (TTL неделя): `wp transient delete woodev_extensions_catalog_v2` на стенде.

## Старт сессии
- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
- Контракт коннектора: woodev_theme `plugins/woodev-account-connector/includes/class-purchases.php` + `class-rest-controller.php` (читать живой код).
