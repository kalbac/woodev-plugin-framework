> ✅ **§8 CHECKOUT FIELD LAYER — MERGED** (s44, 2026-07-30, PR #132 → `main` `957c039`, ветка удалена, main синхронизирован 0-behind).
> Всё, что висело с s42, закрыто: реальный сабмит заказа доверифицирован, Codex re-critic на риговых фиксах проведён, интеграционные тесты починены, CI полностью зелёный, `mergeStateStatus: CLEAN`.
>
> **🎯 СЛЕДУЮЩЕЕ — SP-5 «Карта / ПВЗ» (§7).** Решение оператора s44: ранний пилот НЕ берём, из пары SP-4/SP-5 выбран **SP-5**.
> Обоснование: §8 оставил хук/слот под кнопку ПВЗ, но самой кнопки и карты нет — сейчас A2-гейт разблокируется только демо-заглушкой фикстуры,
> то есть чекаут доставки нефункционален. И СДЭК, и Яндекс, и будущий OZON — ПВЗ-центричные. DaData (SP-4) садится поверх готового §8-слоя и ничего не блокирует.
>
> **Способ (как в §8, он себя оправдал):** `brainstorming` (заземляясь на РЕАЛЬНОМ коде — что уже есть в скелете
> `woodev/shipping-method/checkout/class-pickup-checkout-handler.php`, какой слот оставил §8, как v1-плагины рисуют карту) →
> `writing-plans` → subagent-driven impl → Codex-критик + re-critic → **обязательная моя браузерная e2e на риге ДО мерджа**.

## Возобновление (ОБЯЗАТЕЛЬНО)

1. `docs-internal/CURRENT-STATE.md` («Last session context» — s44) + `docs-internal/GOTCHAS.md` (75 готчей).
2. Программа shipping-модуля: `docs-internal/specs/2026-06-25-shipping-module-decisions.md` — **§7 решения** (наша карта + iframe + опциональный `<select>`;
   `<select>`-режим берём ТОЛЬКО если тривиален, иначе выкидываем). Плюс cross-cutting constraints (HPOS-safe meta, no `_n()`, class-map regen).
3. Контракт §8, на который SP-5 садится: `docs-internal/specs/2026-07-06-checkout-field-layer-design.md` + готча
   `checkout-field-takeover-woocommerce-states`.
4. **Риг: порт сменён на `:8973` (dev) / `:8974` (tests)** — 8888 занял проект `woodev_base_theme`, порты вынесены в `.wp-env.override.json` (он в gitignore).
   `npx wp-env start`. admin/password. Продуктовое состояние рига живо: товар #12, зоны Russia + rest-of-world, метод `woodev_test_shipping`, COD,
   страница `/classic-checkout/`, pretty permalinks. NB: `woocommerce_coming_soon` пришлось выключить вручную.
5. **Браузер: гонять чекаут через chrome-devtools MCP, НЕ Playwright MCP** — готча `playwright-mcp-does-not-fire-wc-checkout-ajax` (Playwright молча не поднимает сабмит WC; это стоило s42 целого цикла верификации).
6. Версию НЕ бампать (`@since 2.0.2`).

## Что осталось по программе после SP-5

`SP-4` DaData → `SP-6` расчёт + упаковка → `SP-7` экспорт отправлений → `SP-8` трекинг + статусы → `SP-9` письма →
`SP-10` админ-страница заказов → `SP-11` blocks-адаптер → **Phase E пилот: Яндекс → СДЭК → Почта**.

Цель оператора (озвучена s44): доделать фреймворк и перевести на v2 минимум СДЭК и Яндекс.Доставку — после этого берём новый плагин
**OZON Логистика** (пользователи спрашивают). Чек-листы сохранности данных уже лежат: `docs-internal/migration/edostavka-data-preservation-checklist.md`,
`docs-internal/migration/yandex-data-preservation-checklist.md`.

## Открытые вопросы для брейншторма SP-5

1. Чья карта: своя (Leaflet/Яндекс.Карты) vs iframe карьера vs оба режима — и где проходит шов «механизм фреймворка / домен плагина».
2. Как ПВЗ-пикер садится в §8-слот: контракт данных точки (id, адрес, координаты, расписание, оплата картой) — что из этого framework-обязательное.
3. Мобильный сценарий (модалка vs полноэкранный лист) и поведение при отсутствии JS.
4. Кэширование списка ПВЗ (их бывают десятки тысяч) — на чьей стороне и в каком слое.
5. Что переиспользовать из существующего `class-pickup-checkout-handler.php` (скелет ~30%, строки там ещё английские — issue #133).

## Бэклог, открытый в s44

- **#133** — английские строки в `class-pickup-checkout-handler.php` (на русской витрине смесь языков). Бэклог, доска #6.
- **#134** — в тексте ошибки светится сырой id поля, когда у дескриптора нет label; заодно задать label пикап-полю фикстуры. Бэклог, доска #6.

## Процесс / уроки (применить)

- **Codex-критик:** inline-bundle ≤ ~12KB (15KB не влез — резать; 8–11KB работает стабильно).
  `node <codex-plugin>/scripts/codex-companion.mjs task "$(cat bundle)" --json`; follow-up → `--resume <threadId>`.
  **Re-critic свои фиксы обязательно** — в s44 он поймал незакрытый край в моём же фиксе.
  Критика надо перепроверять по коду: из 6 находок s44 одна была неверна по механике (но привела к настоящему багу рядом), две — недостижимы.
- **Интеграционные тесты гонять локально, а не «под CI»** — `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --no-coverage`.
- **Живая проверка ловит то, что не ловят ни тесты, ни критик** — s42 нашла 5+ багов только в браузере, s44 нашла языковую несогласованность только на отрендеренной странице.
- **Git commit -m с бэктиками/скобками ЛОМАЕТ bash-парсинг** — писать сообщение в файл + `git commit -F <file>`.
- **Мердж:** каждый CI job = pass + `mergeStateStatus: CLEAN` ОТДЕЛЬНЫМ шагом, затем `--squash --delete-branch`, никогда `--auto`.
