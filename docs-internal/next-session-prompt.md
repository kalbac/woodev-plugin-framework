# Промт следующей сессии (s39): SP-3 «поля + валидация» — brainstorm → спека (→ план)

> Написан в конце s38 (2026-06-30). **s38 итог:** **SP-2 SHIPPED** (PR #94 `79a9d67`) — маскировка секретов (`sensitive` + `constant_name`) + универсальный connection auth-контракт (1..N блоков, seam + плагин-callback, `Woodev_Connection_Result`, REST-тест с server-side мёржем секретов) + React-карточка. Оператор rig-аппрувнул после fix-loop; Codex-критик 2 находки исправлены+re-critic'нуты. Оператор зафиксировал **SP-3 решение #1** (live-валидация) и попросил сохранить сессию. **s39 = SP-3 brainstorm → spec** (не сразу код — сначала добить модель валидации с оператором).

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` (раздел «Last session context» — s38) + `docs-internal/GOTCHAS.md` (индекс; s38 добавил 2: `mask-constant-backed-field-even-when-constant-undefined`, `react-missing-key-state-bleed-across-tabs`).
2. Прочитать `docs-internal/FUTURE-BACKLOG.md` → раздел **SP-3** (зафиксированное решение + открытые вопросы) и **SP-2-DEF** (отложенный wipe-secret affordance).
3. Карта shipping-программы: `docs-internal/specs/2026-06-25-shipping-module-decisions.md`. SP-1 спека/план + SP-2 спека/план — для стиля.
4. **Риг:** проектный wp-env dev `:8888` (`npx wp-env start` из PowerShell если погашен). admin/password. Вкладка «Карьер» уже содержит connection + handshake блоки (фикстура SP-2). Прод `:8080`/issuer `:8090` НЕ трогать.
5. Версию НЕ бампать (`@since 2.0.2`).

## 🎯 Задача s39 — SP-3 «поля + валидация»: brainstorm → spec (потом план в этой же или следующей сессии)

**Способ:** сначала **brainstorming** (skill) с оператором — добить модель валидации, заземляясь на РЕАЛЬНОМ коде (`Woodev_Setting`/`register_setting`/`Field_Schema`/`update_value`/`control-field.js`/`field-row.js` + save-путь REST-контроллера + визард `setup-wizard`). НЕ изобретать — изучить, что уже есть (enum-валидация по ключу, `wp_kses_post` richtext, number-коэрсия — gotcha `settings-api-control-save-path-pitfalls`). Затем `writing-plans`.

### Зафиксировано в s38 (решение #1 — НЕ переоткрывать)

- **Live inline валидация** для `email / url / tel / number(min/max/step)`: паттерн **blur-first → live-clear-on-input, когда поле уже помечено ошибкой** (не флагать во время первого набора; убирать ошибку мгновенно, как только значение валидно).
- **`required`**: валидировать на **blur (ушёл из пустого) + на Save**, НЕ на фокус; звёздочка `<abbr>*</abbr>` в лейбле всегда.
- **color / date**: пикеры ограничивают — live не нужен.
- **Два уровня**: клиент = UX (блокирует Save, показывает пер-полевые ошибки), **сервер = авторитетный гейт** (клиент обходится — урок enum-дырки s31).

### Открытые вопросы для брейншторма/спеки (добить с оператором)

1. **`required`-семантика по типам контролов:** что считать «заполнено» для `toggle` (false — это пусто?), `select`/`multiselect` (пустой выбор), `range` (всегда есть значение)?
2. **Контракт серверных ошибок по REST:** форма ответа `POST woodev/v1/settings` при невалидном поле — статус (422?), `{ errors: { settingId: message } }`? Как клиент маппит их обратно на поля. Где валидация в `update_value`/контроллере (не сломать существующую enum/kses-логику).
3. **Визард (`setup-wizard`):** блокировать «Далее» по невалидному шагу? Как required+формат ложатся на шаги. Обе поверхности (`ControlField`/`FieldRow` общие) — звёздочка + ошибки консистентно.
4. **Какие новые флаги на `Woodev_Setting`:** `required` (точно); формат email/url/tel выводится из `controlType` или нужен явный `validate`/`format`? (склон — из controlType, минимум флагов).
5. **Где живут валидаторы:** чистые JS-функции (email/url/tel/number) + PHP-зеркало на сервере. Состояние ошибки — на поле в `ControlField` (blur/debounce-проводка).

## Кросс-катинг (как в SP-1/SP-2)

- Новые классы фреймворка → `php bin/generate-class-map.php` (no Composer в проде).
- i18n без `_n()` (русский — source; gotcha `russian-source-i18n-plural-n`).
- Существующие source-файлы править built-in `Edit`, не Serena `replace_content` (gotcha `serena-replace-content-eol-flip`).
- Build: `npm run build`, коммитить собранные ассеты (assets-parity CI), LF, CSS-версия по `filemtime`. min WP 6.6 → JSX в новых файлах ок.
- PHPStan локально на Windows падает (segfault) — гейт Linux CI. Локально: `composer phpcs` + `composer test:unit` + JS-сборка.
- Интеграция: `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration [--filter X]`. Фоновые wp-env прогоны харнесс иногда «killed» — гонять foreground.

## Критик = GPT-5.5 (Codex) — после реализации

- Рабочий путь = **inline-bundle** (`node <plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`, NO-SHELL framing). **Бандл ≤~12KB** — на 27KB критик завис на 15 мин в s38 (Windows arg-лимит ~30KB = порог зависания на практике); дробить, scss/тесты убирать, слать только security-/логику-критичные диффы. См. gotcha `codex-shell-sandbox-broken-windows`.
- re-critic свои in-place фиксы перед коммитом (`feedback_recritic_own_fixes`).

## Мёрж / процесс

- Ветка `feat/sp3-field-validation` → PR → каждый CI-job pass + state CLEAN → **визуал на rig-аппрув оператора `:8888`** (звёздочки, live-ошибки, blur-поведение, блокировка Save) → `gh pr merge --squash --delete-branch` (никогда `--auto`). Docker-Hub 502 на integration-джобе = транзиент → `gh run rerun --failed`.

## Прочее / бэклог

- **SP-2-DEF:** wipe-secret/disconnect affordance с подтверждением (FUTURE-BACKLOG) — вынесено из SP-2; можно сделать в SP-3 или на пилоте.
- **UK-3/UK-4 wizard** — последняя не-kit поверхность (full-screen; удалить мёртвые `woodev-setup__field*`, дубли `$wd-*`). Можно вставить между SP, по согласованию.
- **SP-программа дальше:** SP-4 (DaData seam) … SP-11 → пилот-миграция (Яндекс→СДЭК→Почта).
- **s-кратно-10 — следующий аудит доков на s40** (последний — s39 не было; последний аудит s39? нет — см. историю; аудит планировался на s40).
- **MCP в конце s38 отвалились** (Supermemory + Obsidian + Telegram) — `sessions/latest-context.md` мог не обновиться; обновить при возможности (не блокер).
