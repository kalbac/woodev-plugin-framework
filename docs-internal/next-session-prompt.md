# Промт для следующей сессии (s26)

> Написан в s25 (2026-06-20). #7 «Мои покупки» + «Куплено» badge ЗАШИПЛЕН (PR #75, `bbc09bb`) и риг-проверен. Скопируй как стартовый бриф. После выполнения — замени на промт для s27.

---

## ⚠️ Самое первое (do first)

1. **Ничего не висит:** s25 полностью смержен. Фреймворк: `main` на `bbc09bb` (PR #75). Просто синхронизируй `main` (`git pull --ff-only`).
2. **Прочитать контекст s25:** `docs-internal/SESSION-LOG.md` (запись s25) + `docs-internal/CURRENT-STATE.md`. Новые готчи s25: `extensions-catalog-fetch-5s-timeout` [`[api/*]`], `serena-replace-content-eol-flip` [`[tooling/*]`].
3. **Проверить Serena MCP** доступна. На Windows для правок СУЩЕСТВУЮЩИХ PHP-файлов используй встроенный `Edit` (Serena `replace_content` ломает EOL → CRLF, см. готчу).

## Что сделано в s25 (контекст)

- **#7 «Мои покупки» tab + «Куплено» badge** — `Woodev_Account_Purchases` (чистый нормализатор + коллектор id), `GET woodev/v1/account/purchases` (подписанный прокси, cap+nonce, 5-мин кэш, stale/empty-обработка, чистка кэша при disconnect/connect), React-вкладка (ленивый async-fetch, кросс-ссылка по id), cyan-бейдж «Куплено» («Установлен» побеждает). Codex-ревью (2 IMPORTANT исправлены). 706 unit, риг-e2e.
- **Фикс уведомления:** failed/denied подключение теперь показывает admin-notice (`render_connect_notice`).

## Кандидаты на s26 (выбор оператора)

1. **Catalog fetch timeout fix (однострочник, рекомендую первым — дёшево, реальный баг).** `Woodev_REST_API_Extensions::remote_json()` использует дефолтный 5s таймаут `wp_safe_remote_get`; issuer `edd-api/v2/products` отвечает ~8.6s холодным → каталог падает в `stale` при пустом кэше. Фикс: `wp_safe_remote_get( $url, array( 'timeout' => 20 ) )` (как 15s у account-клиента). Готча `extensions-catalog-fetch-5s-timeout`. + юнит-тест на передачу таймаута если осмыслено.
2. **#8 установка-из-коннектора** — `WP_Upgrader` + коннекторный `/download/{id}` (EDD SL package URL). **Security-critical, отдельная сессия**, Codex adversarial-review обязателен. Бейдж «Куплено» уже даёт точку входа («Скачать»).
3. **Рейтинг-в-API** (баг woodev_theme, оператор скипнул в s23): публичный `edd-api` не отдаёт `rating` несмотря на наличие отзывов (`query_reviews()`/global-`$post` gap). Кросс-проектная, на стороне woodev_theme.

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
