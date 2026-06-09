# Next-session prompt — packing seam → real rate-calc (via autodev-loop)

> Copy the block below as the opening prompt of the next new session.
> Written 2026-06-09 at the end of session 2 (dispatcher wiring + warehouse redesign, PR #22 merged).

---

## PROMPT (paste this)

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`). Ветка main, PR #22 уже смержен.

### СТАРТ СЕССИИ
1. Прочитай `docs-internal/CURRENT-STATE.md` (верхний digest — session 2, 2026-06-09) и
   `docs-internal/GOTCHAS.md` (индекс — 31 гоча, обрати внимание на `framework/includes-wiring`,
   `shipping/warehouse-identity`, `box-packer/*`).
2. `recall("woodev-framework")` + `recall("user preferences")` в Supermemory.
3. Для PHP-кода — Serena MCP (`find_symbol`, `get_symbols_overview`, `find_referencing_symbols`),
   не `Read` на `.php`. Если Serena недоступна — built-in tools.

### КОНТЕКСТ — что уже есть (session 2, в main)
- **Box-packer dispatcher подключён в прод-загрузку** (`Woodev_Plugin::includes()`): `Woodev_Packer_Dispatcher`
  (нейтральный) + `Woodev_WC_Packer_Dispatcher` (за `is_woocommerce_active()`). Вход —
  `Woodev_Packer_Packable_Item` / DTO `Woodev_Packer_Input_Item`; выход — `Woodev_Packer_Result` /
  `Woodev_Packer_Package_Result` (`to_array()`: `algorithm`, `package_count`, `total_weight`,
  `total_volume`, `packages[]`).
- **Seam в `Shipping_Method` (но пока НИКЕМ не вызывается в rate-flow):**
  - `const FEATURE_BOX_PACKING` (opt-in через `supports()`).
  - настройка `packing_algorithm` (select из `Woodev_Packer_Dispatcher::get_algorithms()`).
  - `protected pack_package(array $package): ?\Woodev_Packer_Result` — берёт `$package['contents']`,
    гонит через `Woodev_WC_Packer_Dispatcher::from_cart_items()` + выбранный алгоритм; null если паковать
    нечего / WC-диспетчера нет.
  - `protected get_packing_algorithm(): string` — валидирует сохранённый алгоритм, fallback на virtual.
- **Rate-flow сейчас:** `Shipping_Method::calculate_shipping()` (final) → `calculate_rate(array $package): ?Shipping_Rate`
  (abstract, реализует подкласс). `pack_package()` в этот путь ещё НЕ вплетён.

### ЗАДАЧА СЕССИИ
**Довести packing-seam до реального rate-calc:** вплести `pack_package()` в поток расчёта ставки так,
чтобы shipping-метод мог упаковать package в parcels и посчитать ставку по упакованным коробкам
(напр. запрос к carrier API на каждый parcel и агрегирование в `Shipping_Rate`, либо вес/габариты-based
расчёт). Конкретный дизайн — определи в начале через brainstorming (это framework-библиотека: реального
shipping-плагина в репо нет, значит делаем **переиспользуемый seam/шаблонный метод на базовом классе**,
который мигрирующий плагин потребляет; валидируй realistic-фикстурой как `RealisticShippingFixtureTest` /
`WarehousesControllerDataPreservationTest`).

Возможные точки дизайна (не обязывающие — реши в brainstorm):
- шаблонный метод: база пакует и вызывает абстрактный `rate_packages(Woodev_Packer_Result, array $package): ?Shipping_Rate`
  (или per-parcel `rate_parcel(...)` + агрегирование) — карриер-специфика остаётся в подклассе;
- либо хук/helper, отдающий packed packages в `calculate_rate` без смены сигнатуры;
- учти `FEATURE_BOX_PACKING` opt-in (методы без него не пакуют), virtual-only корзину (`pack_package` → null),
  и контракт `Shipping_Rate`.

### ЖЁСТКИЕ ПРАВИЛА (прошлые сессии на этом спотыкались)
- **Делать ВСЁ в рамках autodev-loop, НЕ самостоятельно.** Поставь задачи в `.autodev/queue/pending/`,
  гоняй через conductor (worker пишет файлы, codex/GPT-5.5 критик ревьюит каждый контракт-смежный дифф).
  Свой контекст файлами не забивай — это работа воркеров.
- **GPT-5.5 критик — обязателен** на контракт-смежные правки И на свои in-place фиксы перед коммитом
  (no self-certify; в прошлый раз критик отлично ловил реальные баги). Контракт-зоны тут:
  `Shipping_Method` сигнатуры, `Shipping_Rate`, settings-ключ `packing_algorithm`, любые hook-имена.
- **После того как packing→rate-calc приземлится — отдельный проход ревью через GPT-5.5 критика**
  по всей фиче (не только по последнему диффу). Запланируй это явно.
- Массивы — short syntax `[]`, НИКОГДА `array()`. Строки/комментарии — только English.
- Type declarations на всех параметрах/возвратах; докблоки `@since`/`@param`/`@return`
  (shipping-модуль использует `@since 2.0.0` для нового).
- Installed-site контракты НЕ ломать byte-for-byte (method ids, option-ключи, hook-имена, REST ns,
  meta-ключи). Внутренний код на v2 — свободно.
- **CI/PR гигиена:** новый branch создавай **от свежего `main`** (не переиспользуй `autodev/loop-s2` —
  он был squash-смержен и будет конфликтовать). Перед доверием CI проверь
  `gh pr view <n> --json mergeable,mergeStateStatus` (DIRTY → CI не бежит, см. гочу
  `pr-conflict-skips-pull-request-ci`). Локально на PHP 8.5 — помни про
  `reflection-setaccessible-version-guard` (тесты с reflection падают на 7.4/8.0 без гарда).
- Conventional Commits. Мердж PR ТОЛЬКО после зелёных GH Actions и решения оператора.

### НЕ ЗАБЫТЬ (хвост из session 2)
- Known follow-up: `Abstract_Warehouse_Store::save()` не проверяет возврат wpdb — упавший UPDATE
  вернёт 200 со старыми данными. Можно прихватить как отдельную атомарную задачу (меняет контракт `save()`).

### НАЧАЛО
Сначала — brainstorming дизайна (точка вплетения pack_package в rate-flow), затем разложи на
atomic-задачи в `.autodev/queue/pending/`, затем autodev-loop. Спроси оператора, если дизайн-развилка
существенная.
