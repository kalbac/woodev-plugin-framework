# Промт для следующей сессии (s27): #8 — триаж findings + rig e2e + merge

> Написан в s26 part-2 (2026-06-20) автономно ночью. #8 install-from-connector РЕАЛИЗОВАН и закоммичен в обоих репозиториях. **PR #77 ОТКРЫТ, CI зелёный (17/17), НО НЕ смержен** — ждёт твоего триажа findings и rig e2e.

## ⚠️ Состояние (do first)

1. **Framework PR #77** (`feat/account-install-from-connector`, голова `71c1dd5` + docs-коммит): CI зелёный 17/17. **НЕ авто-мержить** — security-critical, есть нерешённые findings.
2. **Connector (woodev_theme `d375d6d`):** закоммичен в outer-репо woodev_theme, **НЕ задеплоен на прод** woodev.ru. Деплой — за тобой (woodev.ru-сторона). 50 connector unit зелёные.
3. **Прочитать:** SESSION-LOG s26-part-2 + CURRENT-STATE. Новая готча `edd-sl-package-download-domain-bound`.

## Что сделано

- **Доставка:** EDD core `edd_get_download_file_url` (purchase-link, привязка к заказу/покупателю, не к домену) — НЕ `package_download` токен (он domain-bound → `site_inactive`). Заземлено по исходникам EDD SL.
- **Connector `GET /download/{id}`:** HMAC + `Purchases::owned_order_item()` (владение) + `Download_Throttle` (rate-limit по аккаунту) + `Install_Download` (signed `woodev_install` маркер обходит per-file лимит только на подписанном пути).
- **Framework `POST woodev/v1/account/install`:** cap `install_plugins`+nonce → `Woodev_Account_Installer` (SSRF host-pin + `Plugin_Upgrader`, **без активации**). React-кнопка «Установить» (idle/installing/done/error) в карточке + «Мои покупки». 723 unit.

## 🔴 ПЕРВОЕ — триаж Codex findings (НЕ автофикснуты, рекомендованная первой)

Codex: **нет CRITICAL/HIGH.** Три находки — реши, какие чинить, я применю + re-critic:

1. **[MEDIUM] Неатомарный rate-limit** (`Download_Throttle::allow`, connector): `get_transient`→`set_transient` не атомарны → пачка параллельных install-запросов на один `customer_id`+`download_id` может слегка превысить лимит. Владение+HMAC всё равно держатся; лимит — мягкий анти-абуз. **Рекоменд.: починить** — атомарный инкремент через object cache (`wp_cache_incr`) с фолбэком на транзиент.
2. **[LOW] http на store-хосте проходит SSRF-гард** (`is_trusted_package_url`, framework): принимает и `http`, и `https` → теоретически cleartext-загрузка пакета. На проде store=https, так что не воспроизводится штатно. **Рекоменд.: починить** — требовать `https` для не-локальных хостов.
3. **[INFO] `woodev_account_install_allowed_hosts`** — локальный trust-boundary (только риг). Оставить test-only. **Рекоменд.: не трогать.**

## 🟡 ВТОРОЕ — rig e2e (со мной, на стенде)

Issuer :8090 ночью был выключен. Для e2e нужно:
- Issuer (woodev_theme wp-env :8090) с EDD SL: download с прикреплёнными файлами (zip), завершённый заказ на тест-покупателя, строка в `{prefix}woodev_account_connections`.
- Consumer (:8888, уже поднят): подключённый аккаунт (миррор token+secret), фильтр `woodev_account_install_allowed_hosts` → добавить issuer-хост (`localhost`), + safe-remote rig-фильтры (готча `wp-safe-remote-request-local-rig`).
- Проверить: кнопка «Установить» → REST `/account/install` → connector `/download/{id}` отдаёт purchase-URL → `Plugin_Upgrader` ставит плагин (неактивным) → состояние done. Проверить отказы: чужой download (403), без `install_plugins` (403), rate-limit (429).

## 🟢 ТРЕТЬЕ — деплой + merge

- Ты деплоишь connector на прод woodev.ru (+ при желании выставляешь `woodev_account_download_rate_limit`).
- Merge PR #77: `gh pr merge 77 --squash --delete-branch` ТОЛЬКО на подтверждённо-зелёном CI (НЕ `--auto`). Ресинк main.

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем. Re-critic собственных правок после фиксов findings (бюджет GPT-5.5 сбрасывается в 6:08). Серена есть; для правок существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL на Windows).
