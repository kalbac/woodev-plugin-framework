# UK-0 Research — WP/WC component inventory + потребность→компонент (UI-kit)

> s36 (2026-06-27), Шаг 1 research. Источник: РЕАЛЬНЫЙ исходник Gutenberg `packages/components` на ветке **wp/6.9** (= то, что в WP 6.9, риг), sparse-clone в `D:\Projects\_ui-kit-reference\` (удаляется в конце сессии). Сверка с ригом: WP **6.9**, `wp-includes/js/dist/components.min.js` шипнут, наши `index.asset.php` зависят от `wp-components`. Min-target проекта — **WP 6.3**.

## 1. Реально доступно сейчас (рег WP 6.9) + что мы уже используем

`window.wp.components` (externals через dependency-extraction; `@wordpress/components` НЕ в node_modules — только транзитивные пакеты wp-scripts). Доступный набор = версия WP на сайте.

Уже импортируем: `TextControl, TextareaControl, ToggleControl, RadioControl, RangeControl, FormTokenField` (control-field), `TabPanel, Notice, Spinner, Button` (settings), `Dropdown` (кастомный wizard-dropdown), `Card*/Flex/FlexItem/Tooltip/ConfirmDialog` (license).

## 2. Экспортный статус целевых компонентов (критично для min WP 6.3)

| Компонент | Статус | 6.3? | Заметка |
|---|---|---|---|
| `TabPanel` | **stable** | ✓ | старый, декларативный (`tabs[]`, `initialTabName`, `onSelect`), есть `orientation: vertical` |
| `Tabs` (новый, Ariakit) | **🔒 privateApis (locked)** | ✗ | **недоступен сторонним плагинам в ЛЮБОЙ версии** — не вариант |
| `Navigator` + `useNavigator` | **stable** | ✓ | path-based (`/parent/child`), встроенная back-навигация; идеален под под-табы (зам.7) |
| `ComboboxControl` | **stable** | ✓ | single-select + поиск (встроенный клиентский) + async (`onFilterValueChange`+контролируемые `options`+`isLoading`) |
| `FormTokenField` | **stable** | ✓ | multiselect + async (`onInputChange`→`suggestions`); уже используем |
| `SelectControl` | stable | ✓ | нативный `<select>`, multi через native; без поиска |
| `CustomSelectControl(V2)` | stable | ✓ | dropdown без поиска |
| `Popover` | **stable** | ✓ | **портал в `document.body`** (createPortal) + floating-ui `flip`/`shift`/`resize` → авто-уход от края viewport |
| `Tooltip` | **stable** | ✓ | Ariakit+floating-ui, портальный; один child + `text` |
| `RangeControl` | **stable** | ✓ | `min/max/step/value/onChange/marks`; thumb=`COLORS.theme.accent` |
| `Card*` | **stable** | ✓ | **full-width по умолчанию** (`width:100%`); `size`, `elevation`, `isBorderless`, `isRounded` |
| `Divider` | **__experimental** | ⚠ | избегаем (дивайдеры мы и так убираем — зам.5) |
| `Dropdown` | stable | ✓ | позиционируется через `Popover` (→ портальный) |

## 3. Карта: 9 замечаний оператора → решение (на компонентах WP)

| # | Замечание | Решение | Источник |
|---|---|---|---|
| 1 | Full-width, не фикс-карточка | `Card` full-width по умолчанию; снять `max-width: 920px` в settings scss | Card stable |
| 2 | «Папочные» табы, не underline | `TabPanel` (stable) + CSS-recolor `.components-tab-panel__tabs-item.is-active::after` → folder-вид (background+radius, спрятать underline) | TabPanel scss |
| 3 | Deep-link на таб | controlled `TabPanel`/`Navigator` + sync URL вручную (как hash-паттерн уже в wizard `app.js:60-96`) | — |
| 4 | Тултип не обрезать за viewport | `Tooltip`/`Popover` (портал+floating-ui flip/shift) вместо CSS-bubble | Popover stable |
| 5 | Убрать дивайдеры между опциями | свой CSS, не рендерить разделители (нынешние `border-top` в wizard scss) | — |
| 6 | Select с поиском + async-подгрузка | `ComboboxControl` (single+search+async). Multi+search+async — кастом (обёртка/расширение) | ComboboxControl |
| 7 | Под-табы (page→tab→sub-section) | `Navigator` (path-based, back) ИЛИ vertical `TabPanel`; сейчас секции рендерятся стопкой `section-view.js` | Navigator/TabPanel |
| 8 | Range «сломанная синяя точка» | **корень не в enqueue** — settings УЖЕ грузит `wp-components` (`class-settings-page-registry.php:449`). Причина тоньше (нет recolor RangeControl в 37-строчном settings scss / конфликт) → проверить визуально на риге в UK-1 | rig-check |
| 9 | Демо всех типов полей | control-field.js уже рендерит multiselect(`FormTokenField`)/richtext; settings их не выставляет → демо-галерея | control-field.js |

## 4. Наша сторона — расхождения (заземление)

- **Нет общего токен-партиала** (подтверждено). cyan расходится: wizard `$wd-accent:#06aedd` ≠ plugins `$wd-accent:#00c9fd`; settings hardcoded `#06aedd`; license на WP-синем `#2271b1` (outlier).
- `src/components/` (общее, используют только wizard+settings): `control-field.js` (switch на 9 типов), `dropdown.js` (**без поиска/async** — зам.6), `richtext.js` (contentEditable+4 кнопки), `icons.js` (6 SVG).
- SCSS объём: wizard 995 / plugins 668 / license 335 / settings 37 строк.
- RangeControl recolor живёт ТОЛЬКО в wizard scss → settings range не перекрашен.

## 5. Решения, вытекающие из research (вход в брейншторм)

1. **Табы:** `Tabs` (Ariakit) исключён (locked). База = `TabPanel` (stable) + recolor под folder-вид; под-табы = `Navigator` или vertical TabPanel.
2. **Select:** `ComboboxControl` закрывает single+search+async из коробки. Multi+search+async — единственный кастом-кусок.
3. **Оверлеи:** перейти с CSS-bubble тултипа на `Tooltip`/`Popover` (портал) — закрывает зам.4 бесплатно.
4. **Токены:** нужен общий `_tokens.scss` (один cyan), импортируемый всеми entry; recolor wp-components (tabs/range/combobox) — в общий партиал.
5. **WC/selectWoo:** не потребовались как источник — WP-компоненты (на которых WC и сам построен) покрывают все 9 замечаний. Оставлено как точечный fallback.

## Related
- `docs-internal/next-session-prompt.md` (s36 план, 9 замечаний, карта 4 поверхностей)
- `docs-internal/specs/2026-06-26-sp1-settings-page-design.md` (SP-1 — поверхность-эталон)
- gotcha `wp-scripts-jsx-runtime-wp66`, `wp-scripts-css-enqueue-version-by-mtime`
