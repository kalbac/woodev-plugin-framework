# Промт для следующей сессии (s12): пилот edostavka — НАЧАТЬ С АУДИТА shipping-модуля

> Написан в s11 (13.06.2026). Скопируй в новую сессию как стартовый бриф. После выполнения — удали этот файл.

---

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`).

## Контекст (что закрыто в s11)
Живой цикл remote-деактивации **полностью доказан** на реальном коде — оба канала (push: прод woodev.ru → публичный staging; pull: локальный двух-стековый rig) + ack + replay-отказ (HTTP 410), а также отображение уведомления 2.0.1. Попутно найден и починен **release-blocking WSOD** (box-packer interface не подключался в `includes()`). Фреймворк **2.0.1** на PR #41 (нейтральное уведомление + фильтр `woodev_licensing_api_url` + interface-fix + бамп). Лицензионная подсистема v2 и анти-пиратская инфраструктура — на этом ЗАВЕРШЕНЫ.

## Задача s12 — пилот миграции edostavka, НО строго audit-first
Оператор по **ручному ревью** считает shipping-модуль фреймворка **не полностью готовым** к продакшну. Поэтому:

1. **ПЕРВЫМ ДЕЛОМ — попроси у оператора его список замеченных нюансов** по shipping-модулю (`woodev/shipping-method/`, `woodev/box-packer/`, warehouse/PVZ, rate-calc, checkout-поля, REST). Это вход аудита. Не начинай миграцию, пока не получишь список и не проведёшь аудит.
2. **Аудит shipping-модуля** против его списка + против паттернов (wiki `v2-extension-point-pattern`, ADR-006 capability-seam, гочи `shipping/*`). Зафиксируй находки в `docs-internal/reviews/`.
3. **Только после аудита** — спека миграции edostavka + **data-preservation контракт-тест (B-11)**: option keys, shipping-method ids + instance settings (`woocommerce_edostavka_{instance}_settings`), warehouse-таблица, order-meta/session-keys, AJAX actions, admin slugs — всё byte-for-byte (см. `docs-internal/migration/edostavka-data-preservation-checklist.md`).

## Переиспользуемый локальный rig (поднят в s11, можно переиспользовать)
- **Issuer** (woodev_theme, локальный woodev.ru + EDD SL + деактиватор): `wp-env` в `d:\projects\woodev_theme`, dev-сайт `http://localhost:8090`. Локальные лицензии в таблице `wp_edd_licenses` (напр. download_id=21 `19bcfa9e…` expired). Authority-pubkey: `wp eval '(new Woodev\EDD\Plugin\EDD\License_Authority())->get_public_key_base64();'` (был `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`).
- **Stand/consumer** (фреймворк wp-env): `http://localhost:8888`, gitignored `.wp-env-stand/` + `.wp-env.override.json` (core 6.9, php 8.1). Стенд `cdek-stand` (download 21) с админ-страницей Tools → Woodev Stand (capture/apply/setkey). Для локального issuer стенд использует: `WOODEV_LICENSE_AUTHORITY_PUBKEY` (define), фильтр `woodev_licensing_api_url`→`host.docker.internal:8090`, `http_request_host_is_external`+`http_allowed_safe_ports` (порт 8090). Канал — **PULL** (push кросс-контейнерно не работает + SSRF).
- **woodev_theme локальная правка (оставлена):** `Push_Delivery::is_safe_target()` → `if ('local'===wp_get_environment_type()) return true;` (только env=local; прод не затронут).
- Остановить оба стека: `wp-env stop` в каждой папке. Запустить: `wp-env start` (woodev_theme первый старт ~10 мин).
- Гоча [[wp-safe-remote-request-local-rig]] — все грабли локального rig.

## Гигиена / контекст
- **Serena** для PHP-навигации; `tests/` и `.wp-env-stand/` Serena игнорирует — там Grep/Read/Edit.
- **Codex shell-sandbox сломан** на этой Windows-машine — критика только INLINE-бандлом (гоча `codex-shell-sandbox-broken-windows`).
- woodev_theme — **локальный репо без remote**; коммиты локальные, пофайлово (рабочее дерево замусорено). Внутренний `woodev-theme/` — отдельный репо с remote `kalbac/woodev-theme`.
- `composer check` зелёный (603 unit, 41 integration baseline) — держать.
- Память: PR мерджить самому при зелёном CI (`feedback_auto_merge_green_ci`); рекомендованную опцию в AskUserQuestion ставить ПЕРВОЙ (`feedback_recommended_option_first`); инфраструктуру (worktree/temp) объяснять одной фразой ДО создания (`feedback_explain_infrastructure_moves`).
- **Проверить статус PR #41** в начале сессии (должен был само-смерджиться при зелёном CI; если нет — домерджить).
