# Промт для следующей сессии (s27): #8 — деплой коннектора + мерж

> Написан в s26 part-2 (2026-06-21). #8 install-from-connector **РЕАЛИЗОВАН, e2e ПРОЙДЕН** (server-side smoke + браузер оператором). PR #77 открыт, CI зелёный. Осталось только: оператор деплоит коннектор на прод woodev.ru → мерж PR #77.

## Состояние

- **Framework PR #77** (`feat/account-install-from-connector`): install-флоу (`POST woodev/v1/account/install`, cap `install_plugins`+nonce, SSRF-гард, `Plugin_Upgrader` без активации) + React-кнопка + full-card спиннер-оверлей + фильтруемый таймаут (install `/download` = 30с). **725 unit**, phpcs/PHPStan/build-parity зелёные. Codex: нет CRITICAL/HIGH; MEDIUM+LOW исправлены и re-критикнуты.
- **Connector (woodev_theme `d375d6d`+`72904dd`)**: `GET /download/{id}` (HMAC+владение) → EDD purchase-URL; signed `woodev_install` маркер обходит per-file лимит; account-scoped rate-limit (атомарный через object cache). **Закоммичен локально, НЕ задеплоен на прод.**

## 🔴 Осталось (оператор)

1. **Задеплоить коннектор на прод woodev.ru** (woodev_theme outer-репо: `woodev-account-connector` + готовый `Install_Download`/`Download_Throttle`/`Purchases::owned_order_item`/маршрут). Фича заработает на проде только после этого.
2. **Мерж PR #77**: `gh pr merge 77 --squash --delete-branch` ТОЛЬКО на подтверждённо-зелёном CI (НЕ `--auto`). Ресинк main.

## Риг (оставлен подключённым для повторных тестов)

- **Issuer :8090** поднят: `npx wp-env start` из woodev_theme — **только через Bash + `MSYS_NO_PATHCONV=1`** (PowerShell-обёртка харнесса глотает вывод wp-env). Тома `c8ec47a5_mysql*` хранят данные s25 между пересозданиями контейнеров.
- **Рег-обвязка вариант A:** wp-env пинит `WP_SITEURL` константой → `update_option` не берёт; issuer mu-plugin `zz-rig-host-rewrite.php` (docker cp в контейнер; локальная копия в woodev_theme root) фильтрует `site_url`/`home_url`/`content_url` → `host.docker.internal:8090`. **Удалить mu-plugin после тестов** (или при деплое — он только для рига).
- **3 стаба** (framework v2, bundled woodev): `.rig-stubs/{image-optimizer-pro,woocommerce-split-payment,edd-tinkoff-payment-gateway}.zip`, прицеплены к продуктам 36/23/26 на :8090 (файл-URL переписаны на host.docker.internal).
- **Consumer :8888**: подключение пересижено (token/secret issuer'а), `woodev-stand.php` (gitignored) добавлен фильтр `woodev_account_install_allowed_hosts`. wp-env сбрасывает `woodev_account_data` между сессиями — пересидеть при надобности (SESSION-LOG s26-pt2).
- **Известный нюанс:** issuer медленный на холодную (6–10с/запрос на wp-env Windows) — прогреть парой запросов; install `/download` уже с таймаутом 30с.

## Готчи

- `edd-sl-package-download-domain-bound` (s26) — почему purchase-link, а не package_download токен; signed limit-bypass.
- EDD download-токен хешируется как `path+'?'+query` (host-independent) — фетч пакета можно делать с любого достижимого хоста.

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем. Серена есть; для существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL на Windows). JS/SCSS не пиннятся в `.gitattributes` к LF — нормализуй `sed -i 's/\r$//'` после Edit.
