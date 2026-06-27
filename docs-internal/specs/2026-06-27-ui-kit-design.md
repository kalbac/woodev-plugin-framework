# UI-kit — общий дизайн-язык для 4 admin-React-поверхностей (design spec)

> s36 (2026-06-27). Брейншторм с оператором. Контекст: у `license`/`plugins`/`settings`/`setup-wizard` свои скрипты и расходящиеся стили (нет общего токен-партиала, cyan продублирован/расходится, license на WP-синем) → строим общий UI-kit. Research-инвентарь: `docs-internal/research/2026-06-27-ui-kit-component-inventory.md` (UK-0). SP-2 (auth+секреты) отложен до готовности kit'а.

## Зафиксированные решения (locked)

| # | Решение |
|---|---|
| 1 | **Канонический акцент = `#06aedd`** (cyan мастера) для всех 4 поверхностей. Статусные цвета license (зелёный/красный/жёлтый) остаются. license уходит с WP-синего `#2271b1` на cyan; plugins — с `#00c9fd` на `#06aedd`. |
| 2 | **Select с поиском/async = нативный `ComboboxControl`** (single, recolor cyan); multi = `FormTokenField`. Кастомный `dropdown.js` удаляется. Принимаем 2 разных UX (single/multi). |
| 3 | **Демо-витрина** `Woodev → UI Kit` — отдельная страница (dev-only). |
| 4 | **PR #89 закрыть** (superseded). UK-1 — свежая ветка; выборочно забрать из #89: демо-фикстуру, WC-метрики (label 182px / control 425px), паттерн `FieldRow`. Отклонённый layout не тащим. |
| 5 | **Min WP = 6.6** (порог авто-JSX-runtime). Убрать classic-JSX хак. |
| 6 | **WC-слой заложить + отложить** (YAGNI): папка `src/components/wc/` + правило гейта + 1 показательная обёртка; массовые WC-обёртки — под реальную потребность. |
| 7 | **Навигация настроек:** папочные табы (провайдеры) → горизонтальные под-табы-ссылки (секции) → поля; deep-link через query-params. |

## Принцип

Kit = тонкий слой поверх нативных компонентов, **не обёртка ради обёртки**. Три задачи: (а) единый источник дизайн-токенов, (б) recolor нативных компонентов под бренд, (в) собственные компоненты только там, где платформа не покрывает. «Не изобретать велосипед» — максимум на нативном.

## Архитектура — двухслойный kit

- **Core-слой `src/components/`** (WP-only) — токены + recolor + base-компоненты. Безопасен везде: и `Woodev_Plugin`, и `Woodev_Woocommerce_Plugin`. Основа всех 4 поверхностей.
- **WC-слой `src/components/wc/`** (опциональный) — обёртки над `@woocommerce/components`, для поверхностей WC-плагинов. Технический гейт: `@woocommerce/components` существует только при активном WC и тянет зависимость `wc-components` → **нельзя статически импортировать в общий бандл** (на не-WC сайте сломает страницу). Грузится только в WC-контексте (отдельный гейт/бандл). Сейчас заложен пустым.

Каждая из 4 поверхностей импортирует `tokens.scss` + `_wp-recolor.scss` через `@use`, убирает свои дублирующие переменные/хардкоды.

## Каталог core-компонентов (`src/components/`)

| Элемент | Что | Судьба |
|---|---|---|
| `tokens.scss` | единый источник: `$wd-accent:#06aedd`, accent-strong/ink/soft, radius, spacing, divider, status-цвета | **новый** |
| `_wp-recolor.scss` | recolor нативных: TabPanel(active)·RangeControl(thumb/track)·ComboboxControl·FormTokenField·Tooltip | **новый** (выносим range-recolor из wizard scss → чинит зам.8) |
| `control-field` | анатомия поля (label+tooltip+desc+control) + диспетчер control-типов | оставить; `select` → **ComboboxControl** |
| `field-row` | раскладка label/control (WC-метрики 182/425px) | **новый** (из #89) |
| `tabs-nav` | папочные табы + горизонтальные под-табы + deep-link sync | **новый** (см. Навигация) |
| `richtext` | contentEditable + toolbar (платформа не даёт лёгкого richtext) | оставить |
| `icons` | SVG-иконки (standalone, для full-screen wizard) | оставить |
| ~~`dropdown.js`~~ | кастомный select без поиска | **удалить** (заменён ComboboxControl) |

## Навигация настроек (зам. 2, 3, 7)

Структура (1 таб = 1 провайдер, как SP-1):
- **Уровень 1 — горизонтальные «папочные» табы** (вверху): провайдеры/карьеры. База — `TabPanel` (stable; новый Ariakit-`Tabs` заперт в privateApis — недоступен) + recolor под folder-вид (`.components-tab-panel__tabs-item.is-active::after` → background+radius, спрятать underline). Зам.2.
- **Уровень 2 — горизонтальные под-табы-ссылки** внутри таба: секции провайдера («Авторизация», «Форма заказа»), по умолчанию первая. Заменяет нынешний рендер секций стопкой с `<h3>` (`section-view.js`). Зам.7.
- **Deep-link** (зам.3): URL `?page=woodev-settings&tab={provider}&section={section}` (query-params, не hash). `tabs-nav` читает tab/section из URL при загрузке, пишет при смене (`history.replaceState`). Прямая ссылка открывает нужный провайдер+секцию.

## Демо-витрина (зам.9)

- Страница `Woodev → UI Kit`, регистрируется **только под dev-гейтом** (константа `WOODEV_UI_KIT_GALLERY`/фильтр; по умолчанию off — клиенты не видят).
- React-entry `src/ui-kit-gallery/` — все компоненты kit'а во всех состояниях + все типы полей. Полигон для изолированного ревью kit до расстановки по поверхностям.

## Min WP 6.6 — следствия

- Убрать `babel.config.js` (classic-JSX хак) → авто-JSX-runtime. Старые `createElement`-файлы продолжают работать (импорт валиден и при авто-runtime); синтаксис мигрируем постепенно при миграции каждой поверхности.
- Обновить декларацию совместимости (bootstrap min-version, readme, docs).
- Gotcha `wp-scripts-jsx-runtime-wp66` → пометить устаревшей после перехода.

## Декомпозиция программы

- **UK-0** research — ✅ done (`docs-internal/research/2026-06-27-ui-kit-component-inventory.md`).
- **UK-1 фундамент (этот цикл):** tokens + recolor + field-row + tabs-nav + control-field(select→Combobox) + удалить dropdown.js + демо-витрина; **перестроить страницу настроек на kit, закрыть все 9 замечаний**; перевести settings+common на авто-JSX (min 6.6); rig-аппрув оператора.
- **UK-2** «Плагины» на kit (убрать `#00c9fd`). **UK-3** «Лицензии» (WP-синий → cyan). **UK-4** мастер (самый крупный, full-screen).
- Потом → **SP-2** (auth+секреты).
- Этот общий design-spec + `writing-plans` отдельно под UK-1; UK-2/3/4 — свои планы позже, по одному (как SP-программа).

## Карта 9 замечаний оператора → решение

| # | Замечание | Решение |
|---|---|---|
| 1 | full-width, не фикс-карточка | `Card` full-width, снять `max-width: 920px` |
| 2 | «папочные» табы | `TabPanel` + recolor (folder-вид) |
| 3 | deep-link на таб | controlled tabs-nav + sync URL (query-params) |
| 4 | тултип-overflow | `Tooltip`/`Popover` (портал в body, floating-ui flip/shift) вместо CSS-bubble |
| 5 | убрать дивайдеры | CSS, не рендерить разделители между полями |
| 6 | select+поиск+async | `ComboboxControl` (single), `FormTokenField` (multi) |
| 7 | под-табы | горизонтальные под-табы-ссылки (секции) |
| 8 | range «сломанная синяя точка» | recolor RangeControl в общем `_wp-recolor.scss` (settings УЖЕ грузит `wp-components`, причина тоньше) + **rig-проверка визуально в UK-1** |
| 9 | демо всех типов | демо-витрина (dev-only) |

## Констрейнты / процесс

- **Build:** `@wordpress/scripts`; авто-JSX после 6.6; LF в `assets/build` (`.gitattributes`); коммитить собранные ассеты (assets-parity CI); CSS-версия по `filemtime`. `npm run build` собирает бандлы (+ новый ui-kit-gallery entry в `package.json`).
- **PHP:** демо-витрина = новая PHP-регистрация страницы → `php bin/generate-class-map.php` (нет Composer в проде).
- **i18n** без `_n()` (русский — source); **Serena EOL** — существующие source-файлы править built-in `Edit`, не Serena `replace_content`.
- **Версия:** плагин НЕ бампать (`@since 2.0.2`); WP-min 6.6 — отдельная декларация.
- **Ревью:** независимый критик **GPT-5.5 (Codex)** — через `/codex:*` и/или inline (корректный способ запуска уточнить через `codex:setup`); **re-critic своих in-place фиксов** перед коммитом. Дополнительно — pr-review-toolkit субагенты / `/code-review`.
- **PHPStan** локально на Windows падает (segfault, environmental) — гейт Linux CI «Lint». Локально: `composer phpcs` + `composer test:unit` + JS-сборка.
- **Мёрж:** ветка → PR → **каждый** CI-job pass + state CLEAN → `gh pr merge --squash --delete-branch` (никогда `--auto`).
- **Дизайн субъективен:** визуал не мержить без rig-аппрува оператора; богато сидировать демо-данные перед ревью.

## Отложено / вне scope

- **WC-обёртки** (конкретные) — под потребность (см. решение 6).
- **SP-2 (auth+секреты)** — после UI-kit. Контекст: `sensitive`-маскирование (Field_Schema эмитит value всегда — дыра) + опциональный `constant_name` wp-config override; per-field dirty-tracking уже есть.
- **UK-2/3/4** — после UK-1, по одному.

## Related
- `docs-internal/research/2026-06-27-ui-kit-component-inventory.md` (UK-0 research)
- `docs-internal/next-session-prompt.md` (s36 план, 9 замечаний, карта 4 поверхностей)
- `docs-internal/specs/2026-06-26-sp1-settings-page-design.md` (SP-1 — поверхность-эталон)
- gotchas: `wp-scripts-jsx-runtime-wp66`, `wp-scripts-css-enqueue-version-by-mtime`, `build-artifacts-eol-lf-windows-parity`, `serena-replace-content-eol-flip`, `russian-source-i18n-plural-n`
