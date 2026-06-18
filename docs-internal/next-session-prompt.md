# Промт для следующей сессии (s22): woodev.ru-side — `woodev-core` API + `woodev-account-connector`

> Написан в s21 (2026-06-19). Скопируй в новую сессию как стартовый бриф. После выполнения — замени на промт для s23.

---

## ⚠️ Это КРОСС-ПРОЕКТНАЯ сессия — работаем в `D:\Projects\woodev_theme`, НЕ в framework

Обе задачи s22 — на стороне **woodev.ru** (исходники в **`D:\Projects\woodev_theme\plugins\`**), а не в `woodev_framework`. Framework-сторона (потребитель) уже готова и **forward-compatible** — она сама подхватит новые поля API, как только стор начнёт их отдавать. Открой `D:\Projects\woodev_theme` как рабочий проект; читай его `CLAUDE.md`/`AGENTS.md`/`docs`.

## Старт сессии (в woodev_theme)
1. `docs-internal/CURRENT-STATE.md` (или аналог) проекта woodev_theme — состояние, баги, next actions.
2. Свежий `SESSION-LOG`/gotchas woodev_theme.
3. **Из framework** (контекст-референс, НЕ редактировать здесь): спек OB-7 `D:\Projects\woodev_framework\docs-internal\specs\2026-06-18-plugins-page-ob7-redesign-design.md` — §7 (auth-контракт) и §8a (store-side snippet); гоча `D:\Projects\woodev_framework\docs-internal\gotchas\edd-api-v2-products-no-post-meta.md`.

## Контекст: что сделано в s21 (framework, всё смерджено)
- License Item 0 пофикшен (PR #68). «Woodev → Плагины» переделан на React-витрину (PR #69) + бренд-полиш (PR #70): wide, грид 4/2/1, компактные карточки, бренд-циан `#00C9FD`, `thumbnails.small`.
- **Нормализатор framework уже forward-compatible:** `Woodev_REST_API_Extensions::normalize_product()` (`woodev/rest-api/controllers/class-rest-api-extensions.php`) уже: предпочитает `info._product_icon` для иконки; **скрывает** товар если `info._coming_soon`/`coming_soon` truthy. Осталось, чтобы стор начал отдавать эти поля. Рейтинг — добавить и в API, и потом в карточку (framework follow-up, см. ниже).

---

## ⭐ Задача №1 (ПЕРВАЯ) — расширить edd-api в `woodev-core`

В плагине **`D:\Projects\woodev_theme\plugins\woodev-core`** добавить в ответ EDD API v2 (`/edd-api/v2/products/`) три поля на каждый товар:
1. **`_product_icon`** — ссылка на иконку товара (у многих товаров есть это мета-поле).
2. **`_coming_soon`** — флаг «скоро / не в продаже» (чтобы прятать снятые товары: сейчас в витрине висят Беру.ру, GOODS.ru и т.п.).
3. **`rating`** — рейтинг товара (рейтинг повышает доверие; оператор попросил добавить раз уж модифицируем ответ).

> **⚠️ Точные имена мета-ключей НЕ подтверждены** — оператор может ошибаться в названиях (`_product_icon`, `_coming_soon`, ключ рейтинга). **Сначала найди реальные ключи** в `woodev-core`/в БД woodev.ru (поиск по `_product_icon`, `coming_soon`, `rating`, `get_post_meta`, `register_meta`, `add_meta_box`), потом маппь. НЕ хардкодь предполагаемые имена.

**Как (паттерн, уточнить под версию EDD на woodev.ru):**
```php
add_filter( 'edd_api_products_product', function ( $data, $info ) {
    $id = $info->ID;
    $data['info']['_product_icon'] = (string) get_post_meta( $id, '<РЕАЛЬНЫЙ_КЛЮЧ_ИКОНКИ>', true );
    $data['info']['_coming_soon']  = (bool)   get_post_meta( $id, '<РЕАЛЬНЫЙ_КЛЮЧ_COMING_SOON>', true );
    $data['info']['rating']        = (float)  get_post_meta( $id, '<РЕАЛЬНЫЙ_КЛЮЧ_РЕЙТИНГА>', true );
    return $data;
}, 10, 2 );
```
- Проверь точное имя/сигнатуру фильтра под установленную версию EDD (`edd_api_products_product` vs `edd_api_products`/`edd_api_products_data`).
- Верификация: дёрнуть `edd-api/v2/products/?number=1` (на woodev_theme-риге и/или на проде после деплоя) и убедиться, что три поля присутствуют.
- Контракт: только ДОБАВЛЯЕМ поля (не ломаем существующие).

**После задачи №1 — framework follow-up (в `woodev_framework`, отдельный мелкий PR):**
- Витрина уже подхватит иконку и скрытие coming-soon автоматически. **Рейтинг** нужно прокинуть в нормализатор (`normalize_product`: `'rating' => isset($info->rating) ? (float)$info->rating : null`) и отрисовать в карточке (звёзды/число) — добавить в `src/plugins-page/catalog.js` + стили + юнит-тест нормализатора. (Можно сделать в этой же сессии, переключившись в framework, либо отдельной s23.)
- ⚠️ **Нюанс тестирования:** framework-витрина хардкодит `https://woodev.ru` (прод), а НЕ woodev_theme-риг :8090. Чтобы проверить подхват новых полей локально — либо задеплоить на прод, либо временно сделать `Woodev_REST_API_Extensions::PRODUCTS_URL/CATEGORIES_URL` фильтруемыми (по аналогии с `woodev_licensing_api_url`) и указать на локальный стор. Рассмотри добавление фильтра как улучшение.

---

## ⭐ Задача №2 — создать плагин `woodev-account-connector` (OB-7 Phase B)

Новый плагин в **`D:\Projects\woodev_theme\plugins\woodev-account-connector`** — серверная (woodev.ru) половина «Подключить аккаунт», по модели **WC Helper** (см. framework-спек §7, выверено по реальному коду WooCommerce).

**Что реализовать (REST на woodev.ru, namespace напр. `woodev-account/v1`):**
- `POST /oauth/request_token` → `{secret}` (по `home_url`,`redirect_uri`).
- `GET /oauth/authorize` → экран входа/одобрения пользователя woodev.ru → редирект назад с `request_token`.
- `POST /oauth/access_token` → `{access_token, access_token_secret, site_id}`.
- `GET /oauth/me` (authenticated) → `{name,email}`.
- `POST /oauth/invalidate_token` (disconnect).
- `GET /purchases` (authenticated, HMAC-SHA256 подпись по `{host,request_uri,method,body}`) → список купленных пользователем товаров (download_id/slug/название/иконка/дата), чтобы framework-панель показала «Мои плагины».
- Хранение/идентичность сайта, привязка к EDD-customer.

**Способ реализации — выбор оператора в начале s22:** **autodev-loop** (worker+critic) ИЛИ ты сам через `codex:*` (`/codex:review`, `codex:codex-rescue`, `/codex:adversarial-review` на архитектуре auth). Спроси оператора, если не указал.

**После Phase B:** включить в framework `woodev_extensions_account_enabled` (фильтр) → панель «Подключить аккаунт» оживает; рига-верификация полного хендшейка end-to-end; флипнуть флаг по умолчанию когда стор-сторона готова.

---

## ⚠️ Висит (контекст)
1. **v2.0.1 framework НЕ выпущен.** Не бампать `VERSION`. Новые символы framework → `@since 2.0.2`.
2. **Публичные framework `docs/` — НЕ трогаем** до готовности (решение s13).
3. Рейтинг и follow-up по витрине (выше) — после задачи №1.

## Подход и гигиена (общие)
- Существенная работа → `brainstorming` → `writing-plans` → атомарные TDD-куски; зелёная сборка после каждого.
- Codex INLINE-бандлом (фоновый rescue молча умирает на Windows-сэндбоксе → проверять, что вердикт вернулся). Re-critic собственных правок. Codex-находки НЕ автофиксить — спрашивать оператора (рекомендованная опция первой в AskUserQuestion).
- Serena для PHP, если загружена (в s21 подключилась поздно → Grep/Read/Edit).
- Мердж: `--squash --delete-branch` после зелёного CI; НЕ `--auto`. После merge: `git fetch && git reset --hard origin/main`.
- Чистить пробы/тест-данные на ригах в конце.

## Риг (framework-сторона, если понадобится)
- Issuer/woodev_theme: `wp-env` в `D:\projects\woodev_theme`, `http://localhost:8090`. Stand (framework consumer): `http://localhost:8888`, логин `admin`/`password`. Драйв состояний: `docker cp` + `wp eval-file` через PowerShell (Git-Bash манглит `/tmp/...`; кириллица/кавычки ломают inline `wp eval`). НЕ `do_action('admin_init')` в wp-cli.
- Браузерная сессия wp-admin на стенде живёт недолго — при долгой верификации придётся перелогиниваться (`admin`/`password`).
