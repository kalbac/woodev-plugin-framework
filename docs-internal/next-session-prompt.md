# Промт для следующей сессии (s23): доводим страницу «Плагины» до конца — полировка каталога + подключение аккаунта end-to-end

> Написан в s22 (2026-06-19). Кросс-проектная сессия. Скопируй как стартовый бриф. После выполнения — замени на промт для s24.

---

## ⚠️ Самое первое (do first)

1. **Запушить висящий docs-коммит s22:** `537f9b7` (`docs(s22): session save…`) лежит ЛОКАЛЬНО — в s22 пуш в `main` заблокировал auto-mode classifier. `git push origin main` из `D:\Projects\woodev_framework` (+ запушить локальный `docs(s22): s23 prompt`).
2. **Сбросить кэш каталога на стенде (:8888):** `wp transient delete woodev_extensions_catalog_v2`. Каталог кэшируется на НЕДЕЛЮ (`Woodev_REST_API_Extensions::CACHE_KEY`), поэтому изменения с прода не видны. После сброса проверить: 5 товаров (OZON / Почта России / Т-Банк / Яндекс Доставка / СДЭК) показывают `_product_icon`; 3 (Wildberries / GOODS.RU / Беру.ру) скрыты.
3. **Спросить оператора про Wildberries:** на проде он помечен `_coming_soon=true` (вместе с ожидаемыми снятыми GOODS.RU/Беру.ру). Это намеренно или случайный `edd_coming_soon` мета на живом товаре? Если случайно — оператор снимает флаг на проде.
4. **Рейтинг:** на проде НИ У ОДНОГО товара нет рейтинга в API → `woodev_get_review_data()` считает 0 одобренных отзывов. Фича рейтинга (PR #71) рабочая, но данных нет. Разобраться: логика подсчёта слишком строгая или отзывов реально нет.

## Где работаем
Кросс-проектно: **framework** (`D:\Projects\woodev_framework`) — UI каталога + **клиент аккаунта**; **woodev_theme** (`plugins\woodev-account-connector`) — доработки коннектора. Деплой на прод — шаг оператора.

## Старт сессии
- framework: `docs-internal/CURRENT-STATE.md`, этот промт.
- woodev_theme: `docs/CURRENT-STATE.md` (s127), `docs/superpowers/specs|plans/2026-06-19-woodev-account-connector-*`.
- **Контракт клиента (auth):** framework `docs-internal/specs/2026-06-18-plugins-page-ob7-redesign-design.md` §7.

## Что сделано в s22 (контекст)
- **woodev-core edd-api:** `info._product_icon` + `info._coming_soon` на проде (проверено в живом API). `rating` уже был (top-level 0–100 из отзывов).
- **Новый плагин `woodev-account-connector`** (woodev_theme): 6 эндпоинтов `woodev-account/v1` + экран авторизации + таблица `woodev_account_connections` + HMAC Signer + маппинг покупок EDD. 31 юнит-тест, рига-верифицирован, Codex-усилён (timestamp-freshness ±300s / атомарный consume / same-origin). Развёрнут и активирован на проде.
- **Framework PR #71 (merged):** `normalize_product()` отдаёт 0–5 `rating` + звёзды в карточке.

---

## Задачи — довести «Плагины» до конца

### A. Полировка каталога (framework, мелочь)
1. **Скрытие coming-soon** — уже сделано в нормализаторе (`_coming_soon`/`coming_soon` → `null`). Только проверить после сброса кэша.
2. **Фолбэк картинки карточки:** `_product_icon` → `thumbnails.small` → **плейсхолдер**. Нормализатор уже строит цепочку до `''`; КАРТОЧКА (`src/plugins-page/catalog.js` `ExtensionCard`) сейчас при пустом `thumbnail` не рисует ничего — добавить плейсхолдер.
3. **Звёзды рейтинга** — сделано (PR #71); зависит от данных отзывов (см. do-first #4).
10. **Вкладка на `plugin-install.php` (OB-8):** переименовать «Woodev» → «Плагины Woodev»; вместо сломанной страницы сделать **редирект** на `admin.php?page=woodev-extensions` (как маркетплейс-вкладка WooCommerce: просто redirect). Найти текущую реализацию — `woodev/.../class-plugin-install-tab.php` (использует fetch/UTM-хелперы `Woodev_Admin_Plugins`).

### B. Подключение аккаунта — клиент framework + коннектор (основная работа)
Построить framework-клиент **`Woodev_Account_Connection`** (спек §7), чтобы оживить хендшейк против живого `woodev-account-connector`. Затем:
- **#6 Дропдаун «Подключить аккаунт»:** [Connect account (OAuth-подключение)] + [ссылка на woodev.ru/my-account] (скрин оператора image #1, в стиле WC Helper).
- **#9 Состояние «подключён»:** на кнопке аватар (`get_avatar_url`, иначе плейсхолдер) + отображаемое имя (`display_name` из `/oauth/me`); в дропдауне [ссылка на my-account] + [Отключить аккаунт] (image #2). Расширить коннектор `/oauth/me` полем аватара (см. решение #9).
- **#5 Акцент «установлен»:** сопоставить store `download_id` ↔ установленные Woodev-плагины через `get_download_id()` (у всех есть); бейдж «Установлен», «Купить» → «Посмотреть».
- **#7 Вкладка «Мои покупки»** (только при подключении): купленные плагины + история заказов (решение #7 = вариант б).
- **#8 Куплен-но-не-установлен:** акцент + бейдж «Куплен» + «Посмотреть» + кнопка **«Установить»** → полная установка через эндпоинт коннектора `/download/{id}` + `WP_Upgrader` (решение #8 = вариант а).

---

## Решения по дизайну — ПРИНЯТЫ оператором (s22)

1. **#8 механизм установки — вариант (а), ПОЛНОЦЕННО в s23. ⚠️ КРИТИЧНО по безопасности (уточнение оператора):**
   - `/download/{id}` **НЕ катает свою выдачу ZIP** — он возвращает **ту же защищённую ссылку, что EDD Software Licensing отдаёт апдейтеру для обновлений** (signed / license-gated package URL, `edd_action=package_download`-типа). Это уже ZIP, и у EDD SL **встроена валидация права на скачивание** на самой ссылке.
   - **Двухслойная защита, чтобы НЕ было несанкционированных установок:** (1) коннектор по подписанному access-token резолвит customer и проверяет, что аккаунт реально **владеет** этим download (покупка / активная лицензия), и только тогда отдаёт защищённый URL; (2) EDD SL **повторно валидирует** право при фактическом скачивании по этому URL. Serious approach — Codex adversarial-review обязателен на проверку владения + на то, что URL нельзя получить/переиспользовать без права.
   - framework-сторона: получает защищённый URL → `WP_Upgrader` ставит/обновляет (модель WC `auto-install`). Обновления — тот же механизм (это и есть штатный EDD SL update-пакет).
   - **Подготовка:** разобраться, как именно EDD SL формирует package URL для апдейтов на woodev.ru (`class-licensing.php` / EDD SL `get_version`/`package_download`), и как привязать его к лицензии customer'а из подключённого аккаунта.
2. **#7 «Мои покупки» — вариант (б):** купленные плагины (карточки со статусом установлен / есть обновление / не установлен + «Установить») **ПЛЮС** история заказов/покупок (номера заказов, даты, суммы — как в `woodev.ru/my-account`). Значит `/purchases` (или новый эндпоинт) должен отдавать и заказы, не только download-ы.
3. **#9 отображаемое имя + аватар:** пользователь выбирает имя в `woodev.ru/my-account/profile` через опцию **`edd_display_name`** («Отображаемое имя») — EDD profile editor пишет результат в WP `display_name`. `/oauth/me` уже возвращает `display_name` (= этот выбор) + `email`; **добавить URL аватара через `get_avatar_url( $user_id )`** (стандартный WP/Gravatar, своей загрузки аватаров нет). Резолв имени — на сервере, не на клиенте.
4. **#5 детект «установлен» — ПОДТВЕРЖДЕНО:** у ВСЕХ плагинов есть `download_id` (без него плагин не работает). Сопоставлять store `download_id` товара со store-ID зарегистрированных Woodev-плагинов через `get_download_id()`. Надёжно.

---

## Подход и гигиена
- Существенная работа → `brainstorming` → `writing-plans` → атомарные TDD-куски; зелёная сборка после каждого.
- Codex-критик на клиент аккаунта + любые auth/install-изменения коннектора (security-sensitive). Re-critic собственных правок. Codex-находки НЕ автофиксить — спрашивать оператора (рекомендованная опция первой).
- **Framework мердж:** ветка → PR → зелёный CI (вкл. Assets-build-parity) → `--squash --delete-branch`, НЕ `--auto`; ресинк main. **woodev_theme плагины:** локальные коммиты в outer monorepo (master); деплой на прод — шаг оператора.
- Флипнуть `woodev_extensions_account_enabled` (default false) когда клиент+коннектор хендшейк проверен end-to-end на риге.
- v2.0.1 НЕ выпущен → новые framework-символы `@since 2.0.2`. Публичные framework `docs/` — НЕ трогаем (решение s13).

## Риг
- **Issuer (woodev.ru-локально):** woodev_theme wp-env `http://localhost:8090` — `woodev-account-connector` смонтирован (`.wp-env.json`) и активен; таблица создана; `request_token` отдаёт secret.
- **Стенд (framework consumer):** `http://localhost:8888`, `admin`/`password`. Сброс кэша — через wp-cli стенда.
- ⚠️ **Каталог хардкодит прод** `https://woodev.ru` (`Woodev_REST_API_Extensions::PRODUCTS_URL/CATEGORIES_URL`). Чтобы тестировать КЛИЕНТ аккаунта против ЛОКАЛЬНОГО коннектора (:8090) — сделать стор-базу/эти URL фильтруемыми (по аналогии с `woodev_licensing_api_url`) и указать на issuer. Рассмотреть как первый шаг блока B.
- Драйв состояний: `docker exec <cli> wp eval-file` (Git-Bash манглит `/tmp/…`; кириллица/кавычки ломают inline `wp eval`). НЕ `do_action('admin_init')` в wp-cli. Браузерная сессия wp-admin живёт недолго — перелогин `admin`/`password`.
