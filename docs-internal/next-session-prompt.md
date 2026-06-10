# Next-session prompt — выбор направления после shipping-conformance (S3 Licensing на очереди)

> Скопируй блок ниже как стартовый промт следующей сессии.
> Написано 2026-06-10 в конце сессии 4 (shipping conformance audit + предикаты смержены как PR #24 `033368c`).

---

## PROMPT (paste this)

Проект: **Woodev Plugin Framework** (`D:\Projects\woodev_framework`). Ветка `main`, всё смержено.

### СТАРТ СЕССИИ
1. Прочитай `docs-internal/CURRENT-STATE.md` (верхний digest — session 4, 2026-06-10) и
   `docs-internal/GOTCHAS.md` (индекс — 32 гочи).
2. Прочитай `docs-internal/platform-v2-program-tracker.md` — **он устарел** (last updated 2026-06-08,
   всё ещё пишет «S2 next / branch autodev/loop-s2»). На самом деле S2 box-packer завершён
   (PR #21 + wiring PR #22), packing вплетён в rate-calc (s3), модуль доставки прошёл
   conformance-аудит и выровнен по паттерну (s4). **Первое микро-дело сессии — сверить tracker
   с реальностью** (statuses S2 ✅, добавить строки про rate-seam + conformance, обновить «Next action»).
3. Для контекста плана — `PLANS.md` (§3 модули, особенно §3.4 licensing) и
   `docs-internal/FUTURE-BACKLOG.md`.
4. Для PHP — Serena MCP (`find_symbol`, `get_symbols_overview`, `find_referencing_symbols`),
   не `Read` на `.php`. Активируй проект `woodev_framework` через Serena в начале.

### ГДЕ МЫ ПО ПЛАНУ (PLANS.md §3 + program-tracker stage map)
- **S0 Platform Split** ✅ (clean-break, декомпозиция базы, минимальный resolver).
- **S1 Shipping** ✅ (универсальный модуль, merged PR #20).
- **S2 Box-packer** ✅ (minimal-virtual-box + WC-neutral wrapper; dispatcher вшит в прод;
  packing вплетён в `calculate_rate` single-seam template; модуль доставки conformance-чист).
- **S3 Licensing** ⚪ **— следующая запланированная стадия.** PLANS §3.4 / §3.4.1.
- S4 EDD / S5 React admin / S6 Ecosystem orchestration — post-v2.0.

### РАЗВИЛКА — спроси оператора в начале (или он уже укажет)
Три кандидата на эту сессию (мой рекоменд — **A**, начать с брейншторма; B/C можно как warm-up):

**A. Стадия S3 — Licensing (рекомендую начать).** PLANS §3.4 + §3.4.1. Объём:
   1. **`is_need_license`** — флаг (default `true`), переопределяется в плагине. При `false` на
      странице «Woodev → Лицензии» либо прячем поле ключа, либо показываем «лицензия не требуется»;
      проверки актуальности игнорируются, **но обновления продолжают приходить**.
   2. **Современный UI** страницы лицензий (сейчас input+submit → интерактивный). Обратная
      совместимость в плагинах обязательна.
   3. **Встроенные вебхуки** (§3.4.1, два сценария): (a) просроченная лицензия → безопасное
      удалённое отключение/удаление плагина (только мы можем инициировать — подпись HMAC/asymmetric);
      (b) диагностика для будущего `woodev_support` AI-агента без доступа к админке.
   ⚠️ **PLANS §6:** на этапе планирования оператор ждёт ФОРМАТ ДИСКУССИИ, не слепого исполнения —
   агент инициативен, предлагает, оспаривает. → Начни с `superpowers:brainstorming` по S3, потом
   спека (`superpowers:writing-plans`), потом atomic-задачи в autodev-loop.
   🔒 **Release-blocking контракты licensing** (НЕ ломать byte-for-byte): option-ключи лицензии,
   activation state, instance ids, updater identity. Проверь существующий `woodev/licensing/`
   перед дизайном (s2026-06-03 уже сделал v2-split `Woodev_Woocommerce_License_Settings`).

**B. Доведение модуля доставки (§3.2 «идеальный и универсальный»).** Кандидаты: вынести `api/`
   под `Woodev_Plugin` (§3.3, расцепить с WC); добить оставшиеся shipping loose-ends. Меньше
   дизайна, чем S3.

**C. Мелкий safety-фикс (warm-up, ~1 атомарная задача).** `Abstract_Warehouse_Store::save()` не
   проверяет возврат `wpdb` — упавший UPDATE возвращает 200 со старыми данными. Меняет контракт
   `save()`; адверсариальный критик обязателен. Висит открытым с session 2.

### КАК РАБОТАЕМ (жёсткие правила — прошлые сессии на этом спотыкались)
- **ВСЁ существенное — через autodev-loop, НЕ самостоятельно.** Atomic-задачи в
  `.autodev/queue/pending/` (формат — см. `.autodev/queue/done/s4-p1-*.md`), worker-субагенты
  пишут файлы, адверсариальный критик (или silent-failure-hunter / general-purpose как стенд-ин
  GPT-5.5) ревьюит КАЖДЫЙ контракт-смежный дифф И твои собственные in-place фиксы перед коммитом
  (no self-certify). После доводки — отдельный холистический проход критика по всей фиче.
- **Брейншторм объёма с оператором до кода**, если развилка существенная (новые публичные
  контракты, новые `FEATURE_*`, схема БД, формат вебхука). Формат — дискуссия (PLANS §6).
- **НЕ ломать installed-site контракты byte-for-byte** (option-ключи, license/instance ids,
  updater identity, hook-имена, cron, REST ns, AJAX actions, admin slugs, meta-префиксы, DB-схемы).
  Внутренний код на v2 — свободно (clean-break, ADR-005). Guard-зоны — `.autodev/GUARDS.md`.
- Массивы — short syntax `[]`, НИКОГДА `array()`. Строки/комментарии — только English.
  Type declarations + докблоки `@since`/`@param`/`@return` (новое — `@since 2.0.0`).
- **CI/PR гигиена:** новый branch от **свежего `main`**. Перед доверием CI —
  `gh pr view <n> --json mergeable,mergeStateStatus` (DIRTY → CI не бежит, гоча
  `pr-conflict-skips-pull-request-ci`). Локально PHP 8.5 → помни
  `reflection-setaccessible-version-guard` и `brain-monkey-function-pollution`.
- Conventional Commits. Мердж PR ТОЛЬКО после зелёных GH Actions И решения оператора.

### НАЧАЛО
Сначала — микро-сверка program-tracker с реальностью (S2 ✅). Потом спроси оператора про
развилку A/B/C (рекоменд — A: брейншторм S3 Licensing). После выбора A — `superpowers:brainstorming`.
После реализации любого пути — удали этот файл (`docs-internal/next-session-prompt.md`).
