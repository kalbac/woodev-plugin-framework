# Next-session prompt — shipping-module conformance audit vs Capability-Gated Feature Seam (via autodev-loop)

> Copy the block below as the opening prompt of the next new session.
> Written 2026-06-10 at the end of session 3 (packing→rate-calc single-seam template merged as PR #23 `12d6087`; pattern formalized in wiki + ADR-006 `a26ac4a`).

---

## PROMPT (paste this)

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`). Ветка main, PR #23 смержен, доки паттерна на main.

### СТАРТ СЕССИИ
1. Прочитай `docs-internal/CURRENT-STATE.md` (верхний digest — session 3, 2026-06-09/10) и
   `docs-internal/GOTCHAS.md` (индекс — 32 гочи; обрати внимание на `shipping/*`,
   `framework/includes-wiring`, `box-packer/*`).
2. **Прочитай определение паттерна, по которому будешь сверять:**
   - `docs-internal/wiki/capability-gated-feature-seam.md` — описание (5 свойств, конвенции,
     границы «когда НЕ применять», seam-семейство, две точки гейта).
   - `docs-internal/adr/006-capability-gated-feature-seam.md` — решение (эталонный паттерн,
     сильный дефолт, НЕ жёсткий мандат).
   - **Эталон-референс — модуль `woodev/payment-gateway/`** (по `PLANS.md` §3.2 это целевая
     архитектура для shipping). Перечитай ключевые точки: `payment_fields()`,
     `process_refund()`, `init_form_fields()`, `FEATURE_*` константы, предикаты
     `supports_*()`, `add_support()`.
3. `recall("woodev-framework capability gated pattern shipping")` + `recall("user preferences")`.
4. Для PHP — Serena MCP (`find_symbol`, `get_symbols_overview`, `find_referencing_symbols`),
   не `Read` на `.php`. Если Serena недоступна — built-in tools.

### КОНТЕКСТ — что уже есть
- **Паттерн назван и задокументирован** (session 3): «Capability-Gated Feature Seam» —
  опциональное поведение в гарантированно-вызываемой точке потока, под capability-флагом
  (`supports( FEATURE_* )`), с делегированием в именованный seam, inert-by-default,
  `final` где обход = баг.
- **`shipping-method` уже частично соответствует:** `Shipping_Method` объявляет
  `FEATURE_SHIPPING_ZONES`, `FEATURE_INSTANCE_SETTINGS`, `FEATURE_SHIPPING_CLASSES`,
  `FEATURE_BOX_PACKING`; `is_available_for_package()` гейтит shipping-classes;
  `init_form_fields()` гейтит классы + packing; `calculate_rate()` (теперь `final`) гейтит
  box-packing и делегирует в `pack_package()`/`rate_package()` (s3-инстанс паттерна);
  `add_support()` стреляет `woodev_shipping_method_{id}_supports_{name}`.
- **Что в shipping пока НЕ по паттерну (гипотезы — проверь в аудите):** capability проверяются
  «голым» `supports( self::FEATURE_X )` без предикат-обёрток `supports_*()` (в отличие от
  payment-gateway); pickup-подсистема (pickup-point source, checkout, карты, склады, REST),
  tracking, webhook, order-handler могут быть подключены безусловно / отдельной регистрацией,
  а не через capability-гейт — но это может быть **оправданной границей** (standalone
  subsystem → placement #2 / отдельный класс), НЕ автоматически «гэп».

### ЗАДАЧА СЕССИИ
**Аудит `woodev/shipping-method/` на соответствие Capability-Gated Feature Seam, затем
точечная доводка (где оправдано) через autodev-loop.** Это AUDIT-then-remediate, НЕ слепой
рефактор.

**Фаза 1 — Аудит/карта (сначала, до любого кода).** Пройди весь модуль и для каждого
опционального поведения классифицируй:
- ✅ **conforming** — уже по паттерну (5 свойств выполняются);
- 🟡 **justified deviation** — НЕ по паттерну, но это правильно (standalone-подсистема со своим
  жизненным циклом: REST-роуты, cron, webhook, handler с self-gating — см. wiki «when NOT to
  use it» и «two gate placements»);
- 🔴 **real gap** — должно быть по паттерну, но не так (забываемый вызов, ad-hoc булев флаг
  вместо `supports()`, толстый inline-блок вместо тонкого оркестратора, отсутствует
  предикат-обёртка там, где capability проверяется в 2+ местах, capability объявлена не на
  своём уровне охвата).
Оформи это как conformance-отчёт `docs-internal/reviews/shipping-pattern-conformance-audit-2026-06-XX.md`
(таблица: behaviour → файл:строка → класс → вердикт → почему → предлагаемое действие).

**Фаза 2 — Брейншторм объёма с оператором.** Аудит, скорее всего, покажет, что shipping
**в основном conforming**, а реальных гэпов немного. Реши с оператором, что чиним: вероятные
кандидаты — (а) ввести предикат-обёртки `supports_box_packing()` / `supports_shipping_classes()`
для читаемости и единообразия с payment-gateway; (б) выровнять capability-словарь; (в) любые
🔴-гэпы. НЕ тащи паттерн в 🟡-границы. **Спроси оператора, если развилка существенная**
(новые `FEATURE_*` константы, изменение публичной поверхности).

**Фаза 3 — Atomic-задачи в `.autodev/queue/pending/`** на каждый согласованный фикс.

**Фаза 4 — autodev-loop** (worker пишет → критик ревьюит → composer check) → холистический
ревью всей доводки.

### ЧТО ИСКАТЬ (критерии из wiki)
5 свойств: (1) гарантированная точка вызова; (2) opt-in через `supports( FEATURE_* )`,
желательно предикат-обёртка; (3) база = тонкий оркестратор, специфика в seam (абстрактный
метод / пустой stub / handler / API); (4) inert-by-default; (5) `final` где обход = баг.
Конвенции: предикат-обёртка при 2+ вызовах; тонкий оркестратор (не god-method); capability на
своём уровне охвата (метод/плагин). Анти-паттерны: `$is_x` булев флаг в конструкторе с
ветвлением; толстый inline-блок; ad-hoc чтение опций вместо `supports()`.

### ЖЁСТКИЕ ПРАВИЛА (прошлые сессии на этом спотыкались)
- **ВСЁ в рамках autodev-loop, НЕ самостоятельно.** Задачи в `.autodev/queue/pending/`,
  worker-субагенты пишут файлы, GPT-5.5/критик (или silent-failure-hunter как стенд-ин)
  ревьюит каждый контракт-смежный дифф И твои собственные in-place фиксы перед коммитом
  (no self-certify — в прошлый раз критик поймал реальное усиление: `final`).
- **После доводки — отдельный холистический проход критика по всей фиче**, не только по
  последнему диффу. Запланируй явно.
- **НЕ форсировать паттерн.** wiki прямо перечисляет границы «when NOT to use it».
  Превратить standalone-подсистему (REST/cron/webhook/pickup-handler) в ветку hot-path —
  это регресс, а не улучшение. Критик должен ловить over-refactor.
- **Installed-site контракты НЕ ломать byte-for-byte:** method ids (`edostavka`,
  `yandex_delivery_express`/`_other_day`), option-ключи (`woocommerce_{id}_settings`,
  `packing_algorithm`), hook-имена (вкл. `woodev_shipping_method_{id}_supports_{name}` из
  `add_support()`, `woodev_shipping_*`), REST ns (`yandex-delivery`), order-meta префиксы
  (`_yandex_delivery_`), warehouse table (`wc_yandex_delivery_warehouses`). Внутренний код на
  v2 — свободно (clean-break, ADR-005). Guard-зоны — `.autodev/GUARDS.md`.
- Массивы — short syntax `[]`, НИКОГДА `array()`. Строки/комментарии — только English.
  Type declarations + докблоки `@since`/`@param`/`@return` (новое в shipping — `@since 2.0.0`).
- **CI/PR гигиена:** новый branch от **свежего `main`** (не переиспользуй смерженные ветки).
  Перед доверием CI — `gh pr view <n> --json mergeable,mergeStateStatus` (DIRTY → CI не бежит,
  гоча `pr-conflict-skips-pull-request-ci`). Локально PHP 8.5 → помни
  `reflection-setaccessible-version-guard` и `brain-monkey-function-pollution`
  (`@runInSeparateProcess` для stub-изоляции).
- Conventional Commits. Мердж PR ТОЛЬКО после зелёных GH Actions и решения оператора.

### НЕ ЗАБЫТЬ (открытый хвост)
- `Abstract_Warehouse_Store::save()` не проверяет возврат wpdb — упавший UPDATE вернёт 200 со
  старыми данными. Отдельная атомарная задача (меняет контракт `save()`), если решишь прихватить.

### НАЧАЛО
Сначала — Фаза 1 (аудит-карта модуля, conformance-отчёт). Потом брейншторм объёма доводки с
оператором. Потом atomic-задачи в `.autodev/queue/pending/` и autodev-loop. Спроси оператора,
если развилка существенная. После реализации — удали этот файл
(`docs-internal/next-session-prompt-shipping-pattern-audit.md`).
