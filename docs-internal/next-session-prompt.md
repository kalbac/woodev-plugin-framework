# Промт для следующей сессии (s35): SP-2 «Auth + секреты» → brainstorm → spec → план → реализация

> Написан в конце s34 (2026-06-26). **s34 = SP-1 «Страница настроек» СПЛАНИРОВАНА И ОТГРУЖЕНА** (PR #87 `39d31a6`): `writing-plans` → 2 раунда независимых критиков → CI-green (incl. PHPStan + 3× integration) → squash-merge. Первый sub-project shipping-модуля закрыт. Дальше по программе — **SP-2 (auth + секреты)**, начиная с брейншторма (у SP-2 ещё НЕТ дизайн-спеки — только §5 решений в decisions-doc).

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` (раздел «Last session context» — s34) + `docs-internal/GOTCHAS.md` (индекс; новая `integration-test-global-admin-hooks-output-and-submenu-accumulation`).
2. Прочитать **`docs-internal/specs/2026-06-25-shipping-module-decisions.md`** — §5 (секреты), §9 (сервисы), §2/§4 (программный контекст). **§5 уже зафиксирован:** НЕТ обязательного DB-шифрования; `sensitive`-маскирование (всегда) + опциональный `constant_name` wp-config override (секрет никогда не в БД; endorsed > шифрование). SP-2 = превратить это в реализуемый дизайн поверх SP-1.
3. (Справочно) изучить **отгруженный SP-1** — на нём SP-2 строится: `woodev/settings-page/` (`Settings_Page_Registry`, `Settings_Provider`, `Settings_Section`, `Field_Schema`), `woodev/rest-api/controllers/class-rest-api-settings-page.php`, `woodev/settings-api/abstract-class-settings.php` + `class-control.php` (control-типы, save-path), `src/settings-page/` + `src/components/control-field.js`. Спека/план SP-1: `docs-internal/specs|plans/2026-06-26-sp1-settings-page-*`.
4. **Ветка:** создать `feat/sp2-auth-secrets` от свежего `main` (`git switch main && git pull`). Версию НЕ бампать (`@since 2.0.2`, VERSION=2.0.1 in-dev).
5. **Риг:** проектный wp-env dev `:8888` (если погашен — `npx wp-env start` из PowerShell; прод `:8080`/issuer `:8090` НЕ трогать). admin/password.

## 🎯 Задача s35 — SP-2 «Auth + секреты»

**Шаг 0 — рег-верификация SP-1 (по желанию оператора, в начале):** React-страница `Woodev > Настройки` отгружена и CI-зелёная (server-side + структура), но **в браузере ещё НЕ проверена**. Можно посмотреть на `:8888` (вкладка «Карьер» фикстуры, per-tab save, валидация) — диагностировать/пофиксить, если что-то не так, прежде чем строить SP-2 поверх.

**Шаг 1 — brainstorm (skill `brainstorming`):** по одному решению за раз, **заземляя каждый вопрос на реальный код SP-1** (control-типы, `update_value` save-path, React `control-field.js`, реестр), а не на предположения (правило «ground design in actual logic»). Открытые вопросы для SP-2:
- Новый control-тип/флаг `sensitive` (маскирование в React-контроле: показывать `••••`, «изменить» кнопка; не возвращать секрет в `GET`-схему?). Где это: `Woodev_Control` + `Field_Schema` + `control-field.js`.
- `constant_name` override: как декларируется (на `Woodev_Setting`?), как резолвится при чтении (`get_value` → если константа определена в wp-config, она авторитетна и поле read-only в UI), как НЕ попадает в БД при save.
- Где живёт «auth» как сущность: отдельная секция настроек у провайдера? Поток подключения/проверки токена (если карьер требует OAuth/refresh) — или это домен плагина, а фреймворк даёт только механизм хранения+маскирования? (Помнить правило: **framework = механизм+контракт+хуки; домен/конкретный карьер = плагин**.)
- Контракт сохранности (§5 + back-compat): имена опций секретов — installed-site data contract, не ломать.

**Шаг 2 — spec → `writing-plans` → реализация.** Спека `docs-internal/specs/2026-06-2X-sp2-auth-secrets-design.md`, план `docs-internal/plans/...`. Реализация — **напрямую worker + независимый inline-bundle/субагент-критик** (autodev-loop НЕ использовать: его codex-критик нерабочий на этой Windows-машине — `invoke-critic.ps1` спавнит shell, падает `CreateProcessAsUserW failed:5`, gotcha `codex-shell-sandbox-broken-windows`; `.autodev/` к тому же нацелен на завершённый S2). Worker+critic суть сохраняется: реализую инкрементами TDD, независимый критик ДО мёржа, **re-critic на собственные фиксы** (без self-certify).

## Кросс-катинг-констрейнты (не забыть — те же, что в SP-1)

- **Нет Composer в прод:** после нового класса фреймворка — `php bin/generate-class-map.php` в **той же** задаче (иначе `ClassMapCompletenessTest` краснеет).
- **i18n:** без `_n()` (русский — source; gotcha `russian-source-i18n-plural-n`).
- **Settings-API save-path** (s31): валидация enum по ключу-или-значению, sanitize richtext (`wp_kses_post`), числовая коэрция — переиспользовать (gotcha `settings-api-control-save-path-pitfalls`).
- **React:** `@wordpress/scripts`, classic JSX (`createElement`/`Fragment`), LF в `assets/build`; собранные ассеты коммитить (assets-parity CI). Общие контролы уже в `src/components/` — расширять там, не дублировать.
- **Интеграционные тесты:** НЕ `do_action('admin_menu')` и прочие широкие глобальные admin-хуки (WC-callbacks печатают deprecation на части матрицы → PHPUnit «unexpected output» → красный только на одних версиях); `$menu`/`$submenu` накапливаются между тестами — звать конкретный метод + `unset()` ключ перед ассертом (новая gotcha `integration-test-global-admin-hooks-output-and-submenu-accumulation`).
- **HPOS-safe:** для секретов это про опции, не order-meta; держать в уме на будущие SP.

## Гигиена / процесс (project rules)

- `@since 2.0.2`, версию НЕ бампать. Новый код — namespaces (PSR-4 `Woodev\Framework\*`) + короткие массивы `[]`, type declarations, docblocks, OOP-only, Yoda.
- PHPStan локально на Windows падает (segfault, environmental — gotcha `phpstan-windows-parallel-worker-segfault`); гейт — **Linux CI «Lint»**. Локально гонять `composer phpcs` + `composer test:unit`.
- **Интеграция + PHPStan — только Linux CI** (локально нет `WP_TESTS_DIR`). Цикл: push → читать лог упавшей matrix-ячейки → фикс → re-push (так в s34 поймали 2 версионных красноты).
- Независимое ревью: Codex shell сломан (gotcha `codex-shell-sandbox-broken-windows`) — критик **inline-bundle** методом / pr-review-toolkit субагенты / `/code-review ultra`.
- **Мёрж:** ветка → PR → проверить **КАЖДЫЙ** CI-job = pass + state CLEAN (main НЕ защищён обязательным чеком) → `gh pr merge --squash --delete-branch` (никогда `--auto`). После прямых docs-коммитов в main — **сразу push** (gotcha `git-squash-onto-stale-origin-main-diverge`).

## Процесс программы (напоминание)

- **По одному SP за раз.** Порядок (Фазы A–E в decisions-doc): ✅ SP-1 (настройки, s34) → **SP-2 (auth+секреты)** → SP-3 (поля, классика) → SP-4 (DaData) → SP-5 (карта/ПВЗ) → SP-6 (тариф+упаковка) → SP-7 (экспорт+документы) → SP-8 (трекинг+статусы) → SP-9 (письма) → SP-10 (страница заказов) → SP-11 (блоки) → пилот-миграция (Яндекс→CDEK→Почта).

## Прочее (по приоритету ниже доставки)

- **s-кратно-10 — следующий аудит доков на s40** (последний — методология s39). На s35 не требуется.
- payment-gateway trait extraction (`class-payment-gateway.php` ~3,542 строк) — кандидат.
- review #4: `array()`→`[]` (~797 мест) + type declarations + `@since` sweep + `Generic.Arrays.DisallowLongArraySyntax`.
