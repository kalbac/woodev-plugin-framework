> ⏸️ **RESUME s42 (§8 IN PROGRESS — design DONE, implementing).** Branch `feat/checkout-field-layer`.
> Design+plan+Codex-critic phase COMPLETE & committed: spec `docs-internal/specs/2026-07-06-checkout-field-layer-design.md`,
> plan `docs-internal/plans/2026-07-06-checkout-field-layer-plan.md` (14 tasks: 1,2,3,4,5,6,7,7b,8,9,10,11,12,13 +
> a "Codex hardening amendments" section that amends specific tasks — APPLY the tagged amendment per task).
> **DO NOT re-brainstorm.** Resume: `git checkout feat/checkout-field-layer`; `git log --oneline -8` to see the last
> committed task; continue **superpowers:subagent-driven-development** (fresh implementer per task → spec reviewer →
> code-quality reviewer; NO worktree so Serena works; edit PHP with built-in Edit not Serena; @since 2.0.2, VERSION
> unchanged; regen `woodev/class-map.php` after any new class; full `composer test:unit` after wiring a shared path).
> After all tasks: Codex critic on the impl diff (companion `…/openai-codex/codex/1.0.4/scripts/codex-companion.mjs task`,
> inline bundle ≤~10KB; plan-critic thread `019f34cc`) → present verbatim, ask operator, re-critic own fixes → **my
> browser e2e on `:8888` classic** (cascade / country-takeover RU-vs-FR / block-placement-without-pickup, screenshots)
> → merge after green CI + CLEAN (squash + delete-branch, never `--auto`). The §8 brainstorm below is HISTORICAL.

# Промт следующей сессии: §8 «Checkout field layer» (SP-3-checkout) — brainstorm → spec → plan → impl

> Обновлён 06.07.2026 после **s41: UK-3/UK-4 (визард на UI-kit, PR #99) + SP-2-DEF (очистка секрета, PR #100) SHIPPED**, оба браузер-верифицированы мной на `:8888`. **SP-4 (DaData) ОТЛОЖЕН до §8** (решение s41 — см. ниже). Следующий приоритет — **§8 checkout field layer**, «одна из самых больных точек в реальных плагинах доставки» (слова оператора). Оператор явно попросил начать §8 **свежей сессией** (большой кусок, детальная проработка).

## Возобновление (ОБЯЗАТЕЛЬНО)
1. `docs-internal/CURRENT-STATE.md` («Last session context» — s41) + `docs-internal/GOTCHAS.md` (73 готчи).
2. Программа shipping-модуля: `docs-internal/specs/2026-06-25-shipping-module-decisions.md` — **§8 решения** (state-outside-DOM store + field registry + event delegation; core + 2 тонких адаптера: classic first, blocks fast-follow; A2 validation-gating блокирует размещение заказа, если способ требует ПВЗ а он не выбран). Также cross-cutting constraints (HPOS-safe meta, no `_n()`, class-map regen).
3. **Риг:** wp-env dev `:8888` (`npx wp-env start`). admin/password. Фикстура `woodev-test-plugin` = провайдер «Карьер» (страница настроек + визард). На риге я **стёр conn_password** в ходе e2e SP-2-DEF — оператор восстановит тестовое значение при желании.
4. Версию НЕ бампать (`@since 2.0.2`).

## 🎯 Задача — §8 «Checkout field layer core + classic adapter» (в decomposition-доке это SP-3)
> ⚠️ **Именование:** зашипленный «SP-3» (s39) = валидация полей на **странице настроек**. §8 из decomposition-дока = **checkout-поля** — другое. Здесь речь про §8/checkout.

Слой полей оформления заказа: **store вне DOM + реестр полей + делегирование событий** (НЕ re-binding патчи). Общий core (какие поля, cascade регион→город→адрес, валидация, AJAX/REST endpoints) + **два тонких адаптера**:
- **Classic adapter** (`[woocommerce_checkout]`): читает/пишет store, рендерит DOM через делегирование, синкает WC-сессию.
- **Blocks adapter** (Gutenberg): тот же store, монтирует React в слоты блоков, синкает `wc/store/checkout` — **обязательный fast-follow**, но core проектируется block-ready с первого дня.

**Ключевое (A2):** если способ требует ПВЗ и он не выбран — слой **блокирует размещение заказа** с внятной ошибкой (classic + blocks). Часть контракта поля.
**Референс (обязательно изучить):** WC address-autocomplete API (developer.woocommerce.com/docs/features/address-autocomplete/) — близко к идеалу, но ограничено Address/Postcode (нам нужны Region/City) → как референс, не reuse as-is.

**Принцип:** framework = mechanism + contract + hooks; **доменная специфика (тарифы, single-carrier) — в плагине.** §8 — фундамент, на который потом сядет SP-4 (DaData autocomplete) и SP-5 (карта/ПВЗ).

**Способ:** `brainstorming` (skill, заземляясь на РЕАЛЬНОМ коде — существующий shipping-skeleton `woodev/shipping-method/checkout/`, WC checkout hooks, как v1-плагины делают поля; изучить, что уже есть в скелете) → `writing-plans` → **subagent-driven** (fresh agent, two-stage spec+code-quality review) → Codex-критик + re-critic → **обязательная моя браузерная e2e на `:8888` (classic checkout) перед мерджем**.

### Открытые вопросы для брейншторма (добить с оператором)
1. AJAX vs REST для checkout/ПВЗ-поиска (по decisions-доку: prefer `woodev/v1` REST для нового кода vs WC admin-ajax fragments) — решить в §8.
2. Что уже есть в скелете (`admin/views/html-admin-shipping-method-status.php` ~30% заглушка; checkout-handler; assets непроверены) — что переиспользуем, что переписываем.
3. Границы core vs adapter: где именно проходит шов (store+registry+валидация в core; рендер+session-sync в адаптере).
4. Мин. block-set (город с source, регион, кнопка ПВЗ+модалка, корректная запись order-meta/session) — но кнопка+карта = §7/SP-5, не §8; §8 даёт хук/слот под неё.

## Прочее / бэклог
- **SP-4 DaData** — ОТЛОЖЕН до §8 (FUTURE-BACKLOG «SP-4»); цель = полный address-service (checkout autocomplete + backend нормализация), DaData-специфика в плагине.
- **UI-kit программа ЗАВЕРШЕНА** — все 4 React-поверхности (settings/plugins/licenses/wizard) на общем ките. UK-CFR (кастомные field/section рендереры) — отдельный цикл против реального потребителя (FUTURE-BACKLOG).
- **SP-2-DEF block-level «Отключить»** — deferred (по-полю зашипено s41); добавить когда реальный карьер потребует.
- **UK-3/UK-4** — done (s41). **UK-CFR** — deferred.
- **MCP:** Supermemory не подключён; Obsidian — синкнуть `sessions/latest-context.md` при сохранении сессии.
- **s41-аудит доков** — s41 не кратно 10; следующий триггер s50 (или по запросу).

## Процесс/уроки (применить)
- **Codex-критик:** inline-bundle ≤~12KB (12.8KB был на грани — урезал до 10.8KB, отработал; 11.8KB как-то завис 10 мин). `node <codex-plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`; follow-up → `--resume <threadId>`. **Re-critic свои фиксы.** Для секрет/безопасность-путей — критик обязателен.
- **Git commit -m с бэктиками/скобками ЛОМАЕТ bash-парсинг** (subshell на `(`) — писать длинные сообщения в файл + `git commit -F <file>`.
- **Browser:** Playwright MCP (admin/password), :8888. Кастомный SelectField = кнопка+портал-popover. Скриншоты слать оператору как пруф.
- **Мерж:** всё коммитить на фиче-ветке; после squash `git rev-list --count main..origin/main`==0. Каждый CI job = pass + `mergeStateStatus: CLEAN` отдельным шагом. `--watch --interval 20` в фоне для ожидания CI.
- **Общие компоненты** (`control-field.js`, `_field.scss`) при изменении пересобирают ВСЕ бандлы, которые их import'ят (settings + wizard + gallery) — это норма, стейджить все собранные ассеты.
