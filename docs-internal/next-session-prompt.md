# Промт для следующей сессии (s27 / s26-part-2): #8 установка-из-коннектора

> Написан в s26 (2026-06-20). #7 ЗАШИПЛЕН (PR #75 `bbc09bb`), таймаут каталога исправлен (PR #76 `9d67f67`). Осталась одна большая задача — **#8 установка плагина из каталога**. Security-critical → отдельный фокусный заход.

---

## ⚠️ Самое первое (do first)

1. **Ничего не висит:** s26 полностью смержен. Фреймворк: `main` на `9d67f67` (PR #76; поверх — docs `423f610`). Синхронизируй `main` (`git pull --ff-only`).
   - **Риг-хвост от s25/s26:** consumer (:8888) почищен (disconnected). Issuer wp-env (:8090) был снесён до очистки — если том сохранился, после `wp-env start` остаётся 1 осиротевшая строка в `{prefix}woodev_account_connections` + засиженный EDD-заказ #12 (downloads 33+26, customer 1, admin). Безвредно; почистить при первом старте issuer: `wp eval 'global $wpdb; $wpdb->query("DELETE FROM ".$wpdb->prefix."woodev_account_connections"); edd_destroy_order(12);'`.
2. **Прочитать контекст:** `docs-internal/SESSION-LOG.md` (s26 + s25) + `docs-internal/CURRENT-STATE.md`. Готчи: `extensions-catalog-fetch-5s-timeout` (fixed), `serena-replace-content-eol-flip` [`[tooling/*]`], `rest-endpoint-not-for-browser-cookie-auth`, `wp-nonce-url-esc-html-breaks-js-urls`.
3. **Serena MCP** доступна? На Windows для правок СУЩЕСТВУЮЩИХ PHP-файлов используй встроенный `Edit` (Serena `replace_content` ломает EOL → CRLF).

## Что сделано (контекст)

- **#7 «Мои покупки» + «Куплено» badge (s25, PR #75):** `Woodev_Account_Purchases`, `GET woodev/v1/account/purchases` (подписанный прокси), React-вкладка + бейдж. Риг-e2e.
- **Таймаут каталога (s26, PR #76):** `Woodev_REST_API_Extensions::FETCH_TIMEOUT = 20`.
- База: **707 unit**.

## Задача — #8 установка-из-коннектора (security-critical)

Дать пользователю установить купленный/бесплатный плагин прямо из каталога/«Мои покупки» (кнопка «Скачать»/«Установить» там, где сейчас бейдж «Куплено»).

- **Коннектор (woodev_theme):** нужен новый подписанный endpoint `GET /download/{id}` (или аналог), отдающий EDD SL **package URL** (или сам zip-поток) только для владельца — проверка, что `customer_id` соединения владеет download_id. Изучить EDD SL `get_download`/`get_version` package механизм. Это сторона woodev.ru → согласовать с оператором (там EDD-зависимости ок).
- **Framework:** REST-маршрут (cap `install_plugins` + nonce) → подписанный `request()` к коннектору за package URL → `WP_Upgrader`/`Plugin_Upgrader` с кастомным skin для установки. **Поверхность исполнения кода** — валидировать источник URL, не доверять ответу issuer слепо.
- **UI:** кнопка установки в `ExtensionCard`/`PurchasesTab` (где `purchased && !installed`), состояния progress/done/error.

## Подход и гигиена

- **Codex adversarial-review ОБЯЗАТЕЛЕН** (SSRF на package URL, проверка владения, путь/распаковка zip, capability, nonce). Re-critic собственных правок. Находки НЕ автофиксить — спрашивать (рекомендованная первой).
- `composer check` зелёный база = **707 unit**. JS-сборка перед коммитом (Assets-parity); ассеты LF.
- Мердж: ветка → PR → зелёный CI → `--squash --delete-branch`, НЕ `--auto`; ресинк main.
- `@since 2.0.2`. Публичные `docs/` — НЕ трогаем. Тему woodev-theme — только с согласия оператора (коннектор woodev.ru-специфичен, EDD там ок).
- **Отброшено оператором:** рейтинг-в-API, мгновенные бейджи через bootstrap.

## Подход и гигиена

- `composer check` зелёный база = **706 unit**. JS-сборка (`npm run build`) перед коммитом (Assets-parity); ассеты LF.
- Worker+critic: Codex-ревью на security-чувствительное (#8 — обязательно). Re-critic собственных правок. Находки НЕ автофиксить — спрашивать (рекомендованная первой).
- Мердж: ветка → PR → зелёный CI → `--squash --delete-branch`, НЕ `--auto`; ресинк main.
- v2.0.1 НЕ выпущен → новые символы `@since 2.0.2`. Публичные `docs/` — НЕ трогаем (s13). Тему woodev-theme — НЕ трогать без явного согласия.

## Риг (e2e)

- **Issuer** (woodev_theme wp-env `:8090`): CLI `c8ec47a5035920b76223df8ef5b79e40-cli-1`. **Consumer/стенд** (framework wp-env `:8888`, `admin`/`password`): CLI `de59f74e6d3d19d18a7f7b6608fda7e7-cli-1`. `./woodev`+`assets/build` live-mapped.
- **Подключение аккаунта на риге пересоздаётся** (wp-env сбрасывает опции между сессиями). s25-способ без браузерного OAuth: засидить EDD-заказ + строку в `{prefix}woodev_account_connections` на issuer (`Connection_Store::create` с известным секретом), затем `update_option('woodev_account_data', …)` на consumer с тем же token+secret. См. SESSION-LOG s25.
- ⚠️ `docker cp` в consumer-контейнер ломается на bind-mount overlay → подавай скрипт через `Get-Content … | docker exec -i <cli> wp eval-file -` (PowerShell).
- ⚠️ Кэш каталога (TTL неделя): после очистки прогрей с длинным таймаутом — `add_filter('http_request_timeout',fn()=>40); (new Woodev_REST_API_Extensions())->get_items();` — иначе холодный fetch падает (готча выше). Сбросы: `wp transient delete woodev_extensions_catalog_v2` / `woodev_account_purchases`.

## Старт сессии

- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
