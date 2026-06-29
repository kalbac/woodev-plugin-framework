# Промт следующей сессии (s37): UK-2 (Плагины/Лицензии на kit) → решить UK-CFR vs SP-2

> Написан в конце s36 (2026-06-29). **s36 итог:** UI-kit построен и отполирован — **UK-1 (PR #90)** + 2 полиш-раунда (**#91 kit → 10/10**, **#92 settings**). Всё в `main`. Настройки и витрина доведены, console чист. Расширяемость полей (UK-CFR) отложена в backlog. **s37 = UK-2** (мигрировать «Плагины» и «Лицензии» на общий kit), затем **решить: UK-CFR или SP-2**.

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` (раздел «Last session context» — s36 polish + s36 UK-1) + `docs-internal/GOTCHAS.md` (индекс).
2. Прочитать этот файл целиком + спеку `docs-internal/specs/2026-06-27-ui-kit-design.md` (декомпозиция UK-0…4).
3. **Риг:** проектный wp-env dev `:8888` (`npx wp-env start` из PowerShell если погашен). admin/password. Галерея kit видна (`WOODEV_UI_KIT_GALLERY` в `.wp-env.json` dev). Прод `:8080`/issuer `:8090` НЕ трогать.
4. Версию НЕ бампать (`@since 2.0.2`).

## 🎯 Задача s37 — UK-2: «Плагины» и «Лицензии» на общий kit

Цель: довести оставшиеся 2 из 4 admin-React-поверхностей до общего kit (wizard = UK-4, самый крупный, после).

- **UK-2a «Плагины»** (`src/plugins-page/`): перевести scss на `@use '../components/tokens'` (cyan уже унифицирован в `#06aedd` s36, но переменные `$wd-*` ещё локальные — заменить на токены); подключить `_wp-recolor`/`_field`/`_tabs` где уместно; убрать локальные дубли. Красный акцент `#b32d2e` — решить с оператором (оставить как статус-цвет или свести). Rig-проверить.
- **UK-2b «Лицензии»** (`src/license-page/`): **главный visual outlier** — на WP-синем `#2271b1`. Перевести на cyan-токены (`@use tokens` + recolor), сохранив статусные цвета (зелёный/жёлтый/красный для license states). Это закрывает последнее расхождение палитры. Rig-проверить (оператор аппрувит визуал).
- **Метод:** как s36 — мигрировать scss на токены, переиспользовать kit-компоненты где подходят, НЕ ломать существующую вёрстку (license/plugins имеют свои layout — это не полная переделка, а унификация токенов+recolor). Оператор ревьюит на риге батчами (`feedback_operator_manual_ui_testing`).

## Затем — РЕШИТЬ с оператором: UK-CFR или SP-2

Открытое решение (оператор отложил на после UK-2):
- **UK-CFR** (settings extensibility, `FUTURE-BACKLOG.md`) — плагин может добавлять ЛЮБЫЕ кастомные поля/секции, не только встроенные. Два уровня: (a) хук `woodev.settings.controlRenderer` (`@wordpress/hooks`) для любого кастом-поля; (b) кастомная секция целиком (sub-CRUD таблицы — СДЭК упаковки / Яндекс склады). Оператор хочет проектировать ОБА уровня вместе, желательно под реальный плагин (брейншторм→спека→реализация).
- **SP-2** (auth + секреты, отложен с s35) — §5 decisions-doc: `sensitive`-маскирование (Field_Schema эмитит value всегда — дыра маскирования) + опциональный `constant_name` wp-config override (секрет не в DB). Per-field dirty-tracking уже есть. Первый висящий вопрос — граница объёма (только механизм секретов vs + auth-контракт).

## Что есть в kit (s36) — карта

`src/components/`:
- `tokens.scss` — единый источник (accent `#06aedd`, scale, метрики label 182 / control 425).
- `_wp-recolor.scss` — recolor нативных wp-компонентов (TabPanel, RangeControl, и т.д.) + tooltip фикс-ширина + input radius/shadow.
- `_field.scss` — нейтральная анатомия поля (`woodev-field__*`); вертикаль по умолчанию, surface задаёт горизонталь.
- `_tabs.scss` — под-табы (`woodev-subtabs`).
- `field-row.js` — анатомия (label+tooltip[wp Tooltip портал]+desc+control).
- `control-field.js` — диспетчер control-типов (text/textarea/toggle/radio/range/richtext/select/multiselect/color/email/password[eye]/number/date). select/multiselect → `select-field.js`. Неизвестный тип → сейчас падает в text (UK-CFR добавит хук).
- `select-field.js` — **WC-style** select: trigger button + popover (SearchControl + список + галочки), single & multi, overlay (не сдвигает страницу).
- `richtext.js`, `icons.js`.
- `Woodev → UI Kit` dev-витрина (`src/ui-kit-gallery/`, gated `WOODEV_UI_KIT_GALLERY`).

`src/settings-page/` — эталон на kit: `TabsNav` (папочные табы провайдеров + под-табы секций + deep-link `?tab=&section=`), full-width Card, Save-disabled-until-change, WP snackbar + inline notice, описания секций.

## Кросс-катинг-констрейнты

- **React:** `@wordpress/scripts`, **min WP 6.6 → автоматический JSX-runtime** (babel.config.js удалён; новые файлы можно на JSX-синтаксисе, старые createElement работают). **SVG-атрибуты в createElement — camelCase** (`strokeWidth`, не `stroke-width`). На всех wp-инпутах ставить `__next40pxDefaultSize` (иначе 36px-deprecation warning). LF в `assets/build` (gotcha `build-artifacts-eol-lf-windows-parity`); собранные ассеты коммитить (assets-parity CI). CSS версионируется по `filemtime`.
- **Нет Composer в прод:** новый PHP-класс фреймворка → `php bin/generate-class-map.php` в той же задаче.
- **i18n:** без `_n()` (русский — source; gotcha `russian-source-i18n-plural-n`).
- **Serena EOL-флип:** существующие source-файлы править built-in `Edit`, не Serena `replace_content` (gotcha `serena-replace-content-eol-flip`).
- **Build:** `npm run build` собирает 5 бандлов (license/plugins/setup-wizard/settings/ui-kit-gallery).

## Гигиена / процесс

- PHPStan локально на Windows падает (segfault, environmental); гейт — Linux CI «Lint». Локально: `composer phpcs` + `composer test:unit` + JS-сборка. Интеграция через `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --no-coverage [--filter X]`.
- **Критик = GPT-5.5 (Codex):** companion auth работает, но built-in `review` всё ещё упирается в Windows-sandbox shell → **рабочий путь = inline-bundle** (`node <plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`, NO-SHELL framing, bundle **<~30KB** — Windows arg-лимит; дробить/убирать scss). См. gotcha `codex-shell-sandbox-broken-windows` (обновлена s36). re-critic свои фиксы.
- **Мёрж:** ветка → PR → проверить **КАЖДЫЙ** CI-job = pass + state CLEAN → `gh pr merge --squash --delete-branch` (никогда `--auto`). После прямых docs-коммитов в main — сразу push.
- **Дизайн субъективен:** оператор ревьюит UI на риге батчами, не мержить визуал без его аппрува; богато сидировать демо (фикстура «Карьер» уже покрывает все типы полей по 3 секциям).

## Прочее

- **PR #90/#91/#92 — все в main.** main HEAD после session-save = docs-коммит s36.
- **Obsidian MCP был недоступен в конце s36** (disconnected) — `sessions/latest-context.md` не обновлён за этот save; обновить при следующей возможности (не блокер).
- **s-кратно-10 — следующий аудит доков на s40** (последний — s39).
