# Промт следующей сессии (s38): реализовать SP-2 (секреты + auth-контракт) по готовому плану

> Написан в конце s37 (2026-06-29). **s37 итог:** **UK-2 SHIPPED** (PR #93 `60c622e` — Плагины + Лицензии на общий kit, последний WP-синий outlier закрыт, оператор аппрувнул). **SP-2** выбран (оператором) вместо UK-CFR → брейншторм (4 решения) → **спека + 14-задачный TDD-план закоммичены, кода ещё НЕТ**. **s38 = исполнить план** (subagent-driven + Codex GPT-5.5 критик), визуал — на rig-аппрув оператора перед мёржем.

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` (раздел «Last session context» — s37) + `docs-internal/GOTCHAS.md` (индекс).
2. Прочитать **спеку** `docs-internal/specs/2026-06-29-sp2-secrets-auth-design.md` + **план** `docs-internal/plans/2026-06-29-sp2-secrets-auth-plan.md` целиком.
3. **Риг:** проектный wp-env dev `:8888` (`npx wp-env start` из PowerShell если погашен). admin/password. Прод `:8080`/issuer `:8090` НЕ трогать.
4. Версию НЕ бампать (`@since 2.0.2`).

## 🎯 Задача s38 — реализовать SP-2 по плану

**План = `docs-internal/plans/2026-06-29-sp2-secrets-auth-plan.md` — 14 TDD-задач, по одной.** Код в шагах конкретный, заземлён на реальных исходниках (REST-контроллер, реестр, `control-field.js`, `register_setting`, фикстура «Карьер» — всё прочитано в s37).

**Способ исполнения (оператор выбрал):** **subagent-driven-development** — свежий субагент на каждую задачу, ревью между задачами. **НЕ забыть ревью-критика Codex GPT-5.5** (см. ниже) после реализации (минимум финальный проход Task 13; по-хорошему — после крупных PHP-задач). re-critic своих in-place фиксов перед коммитом.

**Что строим (суть, детали — в спеке):**
- **Маскировка:** флаг `sensitive` на `Woodev_Setting`; `Field_Schema` эмитит `value=''` + `is_set` (секрет в браузер не уходит). preserve-on-unchanged автоматом (dirty-tracking шлёт только изменённые поля); явная «Очистить» для wipe.
- **`constant_name`:** секрет в `wp-config` — приоритет в `Woodev_Setting::get_value()`, skip-write в handler `update_value()`, всегда маскируется, read-only в UI.
- **Auth-блок (универсальный, без пресетов):** `Settings_Section` с `is_connection` — free-form поля ИЛИ **0 полей = handshake** + кнопка-действие (лейбл настраиваемый); **1..N блоков на провайдера**. Seam: интерфейсы `Woodev_Settings_Connection_Test` + опц. `_Connection_Status` (маршрут по `connection_id`), VO `Woodev_Connection_Result`. Поведение перевозчика (обмен токена/заголовки/GUID) — в плагине, фреймворк API не трогает.
- **REST:** `POST woodev/v1/settings/{provider}/connection/{id}/test` с server-side мёржем сохранённых секретов для нетронутых полей.
- **React:** маска sensitive + read-only constant в `ControlField`; самодостаточная connection-карточка (`connection-block.js`) + `testConnection` в `rest.js`.
- **Фикстура «Карьер»:** connection-секция (login/password[sensitive]/token[sensitive+constant_name]) + handshake-блок без полей + stub-seam.

## Кросс-катинг-констрейнты (запечены в план, не забыть)

- **Нет Composer в прод:** после новых классов фреймворка (`Woodev_Connection_Result`, 2 интерфейса) → `php bin/generate-class-map.php` (Task 9).
- **i18n:** без `_n()` (русский — source; gotcha `russian-source-i18n-plural-n`).
- **Serena EOL-флип:** существующие source-файлы править built-in `Edit`, не Serena `replace_content` (gotcha `serena-replace-content-eol-flip`).
- **Build:** `npm run build`; LF в `assets/build` (`.gitattributes`); коммитить собранные ассеты (assets-parity CI); CSS-версия по `filemtime`. min WP 6.6 → JSX-синтаксис в новых файлах ок (settings-page бандл — на автоматическом runtime, см. `app.js`/`section-view.js`).
- **PHPStan** локально на Windows падает (segfault, environmental) — гейт Linux CI «Lint». Локально: `composer phpcs` + `composer test:unit` + JS-сборка.
- **Интеграция** через `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration [--filter X]`.

## Критик = GPT-5.5 (Codex) — ОБЯЗАТЕЛЬНО

- Companion auth работает, но built-in `review` упирается в Windows-sandbox shell → **рабочий путь = inline-bundle** (`node <plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`, NO-SHELL framing, bundle **<~30KB** — Windows arg-лимит; дробить, scss убирать). См. gotcha `codex-shell-sandbox-broken-windows`.
- **re-critic свои in-place фиксы** перед коммитом (никогда не self-certify — `feedback_recritic_own_fixes`).

## Мёрж / процесс

- Ветка `feat/sp2-secrets-auth` → PR → проверить **КАЖДЫЙ** CI-job = pass + state CLEAN → **визуал на rig-аппрув оператора `:8888`** (connection-карточка, маска полей, constant-пометка, «Проверить»/«Подключить») — **визуал не мержить без аппрува** → `gh pr merge --squash --delete-branch` (никогда `--auto`). После прямых docs-коммитов в main — сразу push.

## Прочее / бэклог (после SP-2)

- **UK-3/UK-4 wizard** — последняя не-kit поверхность (full-screen; удалить мёртвые `woodev-setup__field*`, дубли `$wd-*`). Можно вставить между SP, по согласованию.
- **UK-CFR** (расширяемость настроек — кастом-поля/секции, `@wordpress/hooks` controlRenderer + sub-CRUD секции) — в `FUTURE-BACKLOG.md`, отдельный цикл под реальный плагин.
- **SP-программа дальше:** SP-3 (поля, классика) … SP-11 → пилот-миграция (Яндекс→СДЭК→Почта). Карта: `specs/2026-06-25-shipping-module-decisions.md`.
- **s-кратно-10 — следующий аудит доков на s40** (последний — s39).
- **Obsidian MCP** был недоступен/нестабилен в конце s37 — `sessions/latest-context.md` мог не обновиться; обновить при возможности (не блокер).
