# Промт для следующей сессии (s34): SP-1 «Страница настроек» → план (writing-plans) → реализация (autodev-loop)

> Написан в конце s33 (2026-06-26). **s33 = две части: (1) autodev-loop hardening — СМЁРЖЕН PR #86 `b7c738e`; (2) SP-1 брейншторм + дизайн-спека — кода НЕ писали.** Дизайн SP-1 утверждён оператором по секциям, спека закоммичена. Дальше — **план реализации (skill `writing-plans`), потом код через autodev-loop** (worker+critic).

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` (раздел «Last session context» — s33 part-1/part-2) + `docs-internal/GOTCHAS.md` (индекс).
2. Прочитать **`docs-internal/specs/2026-06-26-sp1-settings-page-design.md`** — это авторитетная спека SP-1 (5 зафиксированных решений, архитектура, REST, frontend, миграция, тесты, границы).
3. (Справочно) `docs-internal/specs/2026-06-25-shipping-module-decisions.md` §15/§9/§4 — программный контекст; `SHIPPING-PLANS.md` (корень, tracked) — нарратив.
4. **Ветка:** `feat/sp1-settings-page` (спека уже на ней, `c49d7f3`). Сделать `git switch feat/sp1-settings-page`. Если её нет локально — `git switch main && git pull` и пересоздать от main. Версию НЕ бампать (`@since 2.0.2`, VERSION=2.0.1 in-dev).
5. **Риг:** проектный wp-env dev `:8888` (если погашен — `npx wp-env start` из PowerShell; прод `:8080`/issuer `:8090` НЕ трогать). admin/password.

## 🎯 Задача s34 — план + реализация SP-1

**Шаг 1 — `writing-plans`:** превратить спеку в детальный пошаговый план реализации (`docs-internal/plans/2026-06-26-sp1-settings-page-plan.md` или с датой s34). Декомпозиция на инкременты, пригодные для autodev-loop (каждый — чёткий file_set + контракт + критерий приёмки).

**Шаг 2 — реализация через autodev-loop** (worker+critic), как договорились с оператором: re-critic на собственные фиксы (без self-certify), независимое ревью перед мёржем.

### 5 зафиксированных решений (из спеки — НЕ перерешать)

1. **Провайдер = settings-handler `Woodev_Abstract_Settings`** (свой `id` → namespace опций `woodev_{id}_*` → вкладка → легаси-ключ → миграция). Плагин отдаёт 1..N провайдеров (мульти-карьер = N вкладок). Тонкий `Settings_Page_Registry` агрегирует плагины + фреймворк-сервисы.
2. **Хранилище = нативное «опция-на-настройку» (без runtime-адаптера).** Легаси-массив `woocommerce_{id}_settings` — источник **одноразовой Lifecycle-миграции** (доменный код плагина на пилоте Phase E, НЕ в SP-1). SP-1 владеет только **редиректом со старого URL на новую вкладку**.
3. **Реестр + один эталонный провайдер на фикстуре, БЕЗ DaData.** §9 = только seam сервиса (id, schema→вкладка, hook-points); DaData = SP-4.
4. **Один агрегированный app + один контроллер `woodev/v1/settings`**, роутинг по `{provider_id}` (`GET` = схема всех вкладок; `POST /{provider_id}` → `handler->update_value`). Не per-provider контроллеры.
5. **Capability:** база `manage_options`; **WC-зависимый плагин → дефолт `manage_woocommerce`**; явная декларация провайдера переопределяет; видимость родительского `woodev`-меню отработать в плане (WP показывает родителя по первому доступному сабменю — проверить, при необходимости поправить регистрацию).

### Новые сущности (ориентир из спеки)

- `Settings_Page_Registry` (синглтон-агрегатор) + `Settings_Provider` (дескриптор: id/label/handler/секции/capability/legacy_key/legacy_page/§4-spine).
- Seam `Woodev_Plugin::get_settings_providers(): array` (дефолт `[]`; существующий одиночный `get_settings_handler()` остаётся для визарда).
- `Woodev_REST_API_Settings` (woodev/v1, через `Woodev_REST_V1_Registrar`).
- React `src/settings-page/` (вынести общие control-компоненты из `src/setup-wizard/`, не дублировать).
- Меню `woodev-settings` в `Woodev_Admin_Pages` (появляется при ≥1 провайдере).
- Фикстура: эталонный провайдер «Карьер» + сервис-заглушка в `tests/_fixtures`.

## Кросс-катинг-констрейнты (из спеки — не забыть)

- **Нет Composer в прод:** после нового класса фреймворка — `php bin/generate-class-map.php` в **той же** задаче (иначе `ClassMapCompletenessTest` краснеет).
- **i18n:** без `_n()` (русский — source; gotcha `russian-source-i18n-plural-n`).
- **HPOS-safe:** для SP-1 это про опции, не order-meta; держать в уме на будущие SP (gotcha `hpos-order-meta-safety`).
- **React:** `@wordpress/scripts`, classic JSX (`createElement`/`Fragment`, без JSX-синтаксиса), LF в `assets/build`; собранные ассеты коммитить (assets-parity CI).
- **Settings-API save-path** (s31): валидация enum **по ключу-или-значению**, sanitize richtext (`wp_kses_post`), числовая коэрция — уже в коде, переиспользовать (gotcha `settings-api-control-save-path-pitfalls`).

## Гигиена / процесс (project rules)

- `@since 2.0.2`, версию НЕ бампать. Новый код — namespaces (PSR-4 `Woodev\Framework\*`) + короткие массивы `[]`, type declarations, docblocks, OOP-only.
- PHPStan локально на Windows падает (segfault, environmental — gotcha `phpstan-windows-parallel-worker-segfault`); гейт — **Linux CI «Run PHPStan»**. Локально гонять `composer phpcs` + `composer test:unit`.
- Независимое ревью: Codex shell сломан (gotcha `codex-shell-sandbox-broken-windows`) — критик **inline-bundle** методом, либо `/code-review ultra` / pr-review-toolkit субагенты.
- **Мёрж:** ветка → PR → проверить **КАЖДЫЙ** CI-job = pass + state CLEAN (main НЕ защищён обязательным чеком) → `gh pr merge --squash --delete-branch` (никогда `--auto`). После прямых docs-коммитов в main — **сразу push** (gotcha `git-squash-onto-stale-origin-main-diverge`).

## Процесс программы (напоминание)

- **По одному SP за раз.** Порядок (Фазы A–E в decisions-doc): SP-1 (настройки) → SP-2 (auth+секреты) → SP-3 (поля, классика) → SP-4 (DaData) → SP-5 (карта/ПВЗ) → SP-6 (тариф+упаковка) → SP-7 (экспорт+документы) → SP-8 (трекинг+статусы) → SP-9 (письма) → SP-10 (страница заказов) → SP-11 (блоки) → пилот-миграция (Яндекс→CDEK→Почта).

## Прочее (по приоритету ниже доставки)

- payment-gateway trait extraction (`class-payment-gateway.php` ~3,542 строк) — autodev-loop кандидат.
- review #4: `array()`→`[]` (~797 мест) + type declarations + `@since` sweep + `Generic.Arrays.DisallowLongArraySyntax`.
- s-кратно-10 (s40) → docs audit.
