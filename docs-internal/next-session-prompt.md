# Промт следующей сессии (s36): UI-kit — сначала исследовать WP/WC, потом проектировать свой

> Написан в конце s35 (2026-06-27). **s35:** SP-1 рег-верифицирован; найден и **починен release-blocker** меню Woodev (PR #88 `e9b9235`, мёрж); автономный полиш страницы настроек (PR #89, **DRAFT/superseded**) оператор оценил как «намного лучше, но не то». **Главный итог — разворот направления:** у 4 админ-React-поверхностей свои скрипты и расходящиеся стили → **строим общий UI-kit**. **SP-2 (auth+секреты) ОТЛОЖЕН** до готовности kit'а.

## Возобновление (ОБЯЗАТЕЛЬНО)

1. Прочитать `docs-internal/CURRENT-STATE.md` (раздел «Last session context» — s35) + `docs-internal/GOTCHAS.md` (индекс; новая `classmap-autoload-breaks-class-exists-once-guard`).
2. Прочитать этот файл целиком.
3. (Справочно) посмотреть **полиш-ветку** `polish/settings-page-ui` (PR #89 draft) — это НЕ финал, а референс: WC-заземлённые метрики (label 182px / control 425px), паттерн `FieldRow`, токены cyan, богатая демо-фикстура со всеми типами полей. На ней же — мои скриншоты текущего вида настроек.
4. **Ветку под код пока НЕ создавать** — s36 начинается с research + брейншторма (кода нет, пока не утверждён дизайн). Версию НЕ бампать (`@since 2.0.2`).
5. **Риг:** проектный wp-env dev `:8888` (если погашен — `npx wp-env start` из PowerShell). admin/password. Прод `:8080`/issuer `:8090` НЕ трогать.

## 🎯 Задача s36 — UI-kit (по плану оператора)

**Шаг 1 — ИЗУЧИТЬ готовое (operator's explicit ask: «не изобретать велосипед»):**
- **WP `@wordpress/components`** (Gutenberg-дизайн-система): какие React-компоненты есть «из-под капота», их Storybook/дизайн-гайд. Особое внимание тому, что закрывает 9 замечаний: табы (`TabPanel` и есть ли «папочный» вид), `ComboboxControl` (select с поиском), `Tooltip`/`Popover` (портал — решает overflow), `RangeControl`, `Card`, `__experimental*`.
- **WC `@woocommerce/components`** + WC-admin design system: их `SelectControl` (поиск + async, как selectWoo), таб-навигация, layout-примитивы.
- Использовать **context7** для актуальной доки этих библиотек (`mcp__plugin_context7_context7__resolve-library-id` → `query-docs`).
- **Результат шага 1:** инвентарь доступных компонентов + карта «наша поверхность/потребность → их компонент или кастом». Заземление на реальный код наших 4 поверхностей (см. ниже), а не предположения.

**Шаг 2 — брейншторм UI-kit (skill `brainstorming`, по одному вопросу):**
- **Канонический язык дизайна** (первое открытое решение): три «уже отточенные» поверхности расходятся (license=WP-синий `#2271b1`, plugins=красный `#b32d2e`, wizard+settings=cyan `#06aedd`). «Общий знаменатель» обязан свести палитру к одной — **моя рекомендация: брендовый cyan мастера** (он бренд + свежеодобрен). Подтвердить с оператором.
- **Архитектура kit'а:** общий токен-партиал (SCSS, напр. `src/ui/tokens.scss`) + переиспользуемые компоненты (`src/ui/` или расширить `src/components/`), импортируемые всеми entry. Сейчас общего токен-файла НЕТ — каждая поверхность определяет SCSS с нуля (cyan продублирован в 3 местах).
- **Декомпозиция (как SP-программа, по одной за раз):** **UK-0** research → **UK-1** фундамент (токены + ядро компонентов), доказать на странице настроек (закрыть все 9 замечаний) → **UK-2** миграция «Плагины» → **UK-3** «Лицензии» → **UK-4** мастер (самый крупный/особый — full-screen). Потом вернуться к SP-2.
- **Идея (предложить):** демо-галерея компонентов (страница-витрина со всеми типами полей) — ревьюить kit изолированно до расстановки по поверхностям. Закрывает п.9.

**Шаг 3 — spec → `writing-plans` → реализация UK-1.** Спека `docs-internal/specs/2026-06-2X-ui-kit-design.md`, план `docs-internal/plans/...`. Реализация — worker + независимый inline-bundle/субагент-критик, re-critic на свои фиксы (autodev-loop НЕ использовать: codex-shell сломан на этой Windows-машине — gotcha `codex-shell-sandbox-broken-windows`).

## 9 замечаний оператора по текущему UI настроек (= требования к kit'у)

1. **Full-width**, а не карточка фиксированной ширины.
2. Закладки — настоящие **«папочные» табы**, не подчёркнутый текст.
3. **Deep-link** на конкретную закладку (прямая ссылка открывает нужный таб).
4. **Тултип не должен обрезаться** за пределами viewport (длинный текст уходит за край) → портал/`Popover`.
5. **Убрать дивайдеры** между каждой опцией.
6. **Select** — как dropdown мастера; + для длинных списков **поиск внутри** + **динамическая подгрузка** (à la `selectWoo.js`/select2). Проверить `ComboboxControl` (WP) и WC async SelectControl.
7. **Подразделы как под-табы:** основной таб «Карьер» → под-табы-ссылки «Авторизация»/«Форма заказа» (по умолчанию первая); сейчас секции рендерятся стопкой с заголовками — нужно навигацией. (Это то, что обсуждали в s32: page → tabs → sub-sections.)
8. **Range «Наценка»** — сейчас сломанная «синяя точка» вместо слайдера; нужен нормальный слайдер.
9. Демо со **всеми** типами полей (сейчас не видно multiselect, richtext).

## Карта расхождений 4 поверхностей (заземление, s35)

| Поверхность | Файлы | Акцент | Токены |
|---|---|---|---|
| `src/license-page/` | app, card-state, license-card (433), style.scss (334) | `#2271b1` WP-синий | свои, cyan нет |
| `src/plugins-page/` | app, account, catalog (232), install, purchases, filter, style.scss (667) | `#b32d2e` красный | свои |
| `src/setup-wizard/` | app (407), step-view, stepper, style.scss (994) | `#06aedd` cyan | свои `$wd-*` |
| `src/settings-page/` | app, section-view, field-row, rest, style.scss | `#06aedd` cyan | свои (полиш s35) |
| `src/components/` (общее) | control-field (283), dropdown (103), icons (153), richtext (156) | — | используют только wizard+settings |

Общего токен-партиала нет; license-page на WP-синем — главный визуальный outlier.

## Кросс-катинг-констрейнты (те же)

- **React:** `@wordpress/scripts`, **classic JSX** (`createElement`/`Fragment`, без JSX-рантайма — WP 6.3+; gotcha `wp-scripts-jsx-runtime-wp66`), LF в `assets/build` (gotcha `build-artifacts-eol-lf-windows-parity`); собранные ассеты коммитить (assets-parity CI). CSS версионировать по `filemtime` (gotcha `wp-scripts-css-enqueue-version-by-mtime`).
- **Нет Composer в прод:** после нового PHP-класса фреймворка — `php bin/generate-class-map.php` в той же задаче (UI-kit — в основном JS/SCSS, но если добавится PHP — не забыть).
- **i18n:** без `_n()` (русский — source; gotcha `russian-source-i18n-plural-n`).
- **Serena EOL-флип:** для существующих source-файлов править built-in `Edit`, не Serena `replace_content` (CRLF-флип; gotcha `serena-replace-content-eol-flip`).
- **Build:** `npm run build` собирает 4 бандла; entry в `package.json` scripts.

## Гигиена / процесс

- PHPStan локально на Windows падает (segfault, environmental — gotcha `phpstan-windows-parallel-worker-segfault`); гейт — Linux CI «Lint». Локально: `composer phpcs` + `composer test:unit` + JS-сборка. Интеграция + PHPStan — только Linux CI.
- Интеграционные тесты гонять через `npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration .../phpunit.xml --testsuite=Integration --no-coverage`.
- Независимое ревью: Codex shell сломан → критик inline-bundle / pr-review-toolkit субагенты / `/code-review`.
- **Мёрж:** ветка → PR → проверить **КАЖДЫЙ** CI-job = pass + state CLEAN → `gh pr merge --squash --delete-branch` (никогда `--auto`). После прямых docs-коммитов в main — сразу push (gotcha `git-squash-onto-stale-origin-main-diverge`).
- **Дизайн субъективен:** оператор ревьюит UI на риге; не мержить визуал без его аппрува (полиш он отклонял дважды у мастера). Богато сидировать демо-данные перед ревью (`feedback_design_bar_modern_wc_react`).

## Открытые висящие вопросы (для брейншторма)

1. Канонический язык дизайна → **cyan** (рекомендация) vs иной.
2. Searchable/async select: `ComboboxControl` (WP, есть поиск) vs WC async SelectControl vs кастом на базе dropdown мастера.
3. Демо-галерея как отдельная страница-витрина — делать ли.
4. Что делать с PR #89: после UK-1 (ребилд настроек на kit) — закрыть (superseded) или переиспользовать ветку как базу UK-1.

## Прочее

- **SP-2 (auth+секреты) ОТЛОЖЕН** — вернуться после UI-kit. Контекст SP-2: §5 decisions-doc (`sensitive`-маскирование + `constant_name` override), грунт в коде SP-1 (Field_Schema эмитит value всегда — дыра маскирования; per-field dirty-tracking уже есть). Первый висящий вопрос SP-2 — граница объёма (только механизм секретов vs + auth-контракт).
- **s-кратно-10 — следующий аудит доков на s40** (последний — s39).
- PR #88 (меню-фикс) — в main. PR #89 (полиш) — draft, superseded.
