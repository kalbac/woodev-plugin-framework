# s218 overnight plan — «Стоимость доставки за расстояние» 2.0.0 to release-ready

> Written at the end of s217 (05.11.2026). Russian, operator-facing wording kept from the handoff. Spec: [the v2 spec](../specs/2026-11-05-range-shipping-v2-design.md).

## Mode

**s218 — АВТОНОМНАЯ НОЧНАЯ СЕССИЯ (оператор, 05.11.2026): «делай по максимуму, вплоть до релизного состояния; всё подготовь к релизу. Релизить будем после моего ревью на риге».** Режим овернайт (`orca-team` §9): вопросов оператору НЕ задавать, развилку — карточка в «Инбокс» + обратимое решение, дальше. Задача — «Стоимость доставки за расстояние» 2.0.0 по спеке **`docs-internal/specs/2026-11-05-range-shipping-v2-design.md`** (решения D1–D16 в §0 — обязательны, не переоткрывать; карточки и волны — §10.2; открытые пункты — §11). Исследования: `research/2026-11-05-range-shipping-v1-recon.md`, `research/2026-11-05-range-shipping-geo-services.md`. Оператор уже дал «да»: (1) создать приватный репо **`kalbac/woocommerce-range-shipping`**; (2) карточка фреймворка **F1** — общий элемент настроек «таблица записей» (склады и сервисы на нём). Цена 4 000 ₽ (D9) — ставится в EDD только при релизе.

**Граница ночи:** НЕ публиковать — без тега/`gh release` плагина, без EDD, без поста; публикации — после его взгляда на риг. Фреймворк 2.0.26 (нужен для F1) — релизный PR подготовить, мерж `VERSION` — вместе с плагином после его ревью, если плагин пинит коммит; иначе по правилу `merge-and-take-the-next-card` (зелёный CI + критик). Демо-сайт (D10): поручить Homelab AI создать сайт сети по нашей схеме (поддомен вроде `range.wootest.ru`); плагин туда — после релиза.

## Steps

0. Скиллы `orchestration` + `orca-team`; CLI `/Applications/Orca.app/Contents/Resources/bin/orca`; `account list` перед каждым запуском; watchdog (`watch-workers.py`) над КАЖДЫМ воркером и ожидание без «тихих» дыр (память `an-overnight-wave-must-not-end-on-an-unseen-report`, `a-quiet-watchdog-is-not-a-working-worker`).
1. **Репо плагина** по образцу `woocommerce-order-tracking-number` (s214/s215: `sessions/s215.md` строка «initial config commit»): `gh repo create kalbac/woocommerce-range-shipping --private`, `orca.yaml`, `.worktreeinclude`, `.serena/project.yml` (php + typescript), `.gitignore`, local-ci workflow, `orca repo add`; проверочный worktree (vendor есть, Serena находит символ) — `orca-team` §13.
1a. **#1597 — первая карточка ночи, параллельно с репо** (оператор 05.11.2026: «делаем сразу, а то будет дыра в лицензировании», вариант 2): фильтр публичного ключа подписи лицензии (`woodev/licensing/class-license-authority-claims.php` `get_public_key()` и дубль в `class-license-command-dispatcher.php`) применять только при определённой тестовой константе; phpunit-бутстрап и mu-plugin рига `zz-woodev-rig-license.php` (в контейнере) её определяют — проверить, что Ozon/фикстуры на риге и все тесты лицензий зелёные. Варианты и обоснование — `~/Documents/woodev-vds/licence-hardening-s217.md`. В PR/коммитах/карточке механизм обхода НЕ описывать (публичный репо) — «укрепить проверку ключа подписи». Sonnet 5.5 + критик Codex; в фреймворк 2.0.26 вместе с #1598.
2. **Карточки** на доске №6 по §10.2 спеки: F1 — уже есть: фреймворк **#1598** («Бэклог», «Следом»); C1–C13 — issues в репо плагина с префиксом, Russian, label, «Бэклог», «Приоритет» (как `woocommerce-order-tracking-number#1…#6`). Бриф каждой карточки — из карточки + спеки (память `implementation-briefs-carry-the-whole-card-scope`).
3. **Волны** §10.2: W1 F1 ∥ C1 → C2 ∥ C3 ∥ C4 → W2 C5 ∥ C6 ∥ C7 → W3 C8 → C9 ∥ C10 ∥ C11 → W4 C12 → W5 C13. Писатели Sonnet 5.5 (Haiku 5.5 — механика), критик Codex (sol на первом раунде C3/C7/C8/C12 и UI, luna на повторах); мерж в приватный репо = зелёный local-ci координатора + критик другой линии. Абзац о стиле кода в каждом брифе (`green-phpcs-is-not-readable-code`).
4. **Миграция на живых данных v1**: поставить v1 1.2.0 из `~/Projects/legacy-v1/woocommerce-range-shipping` на риг, настроить 2 зоны с правилами (OR/AND, break, abort, %-наценка, «время»), затем обновить на v2 и сверить цены до/после — это доказательство D5 «те же цены».
5. **Риг-приёмка** (C13) классика + блоки, e2e; лицензия на риге — mu-plugin рига (как 58107 у Ozon: добавить 7698 в список фикстур `zz-woodev-rig-license.php` в контейнере или отдельный mu-plugin как `zz-w4-trk-licence.php` в s215).
6. **Пакет материалов** (память `a-plugin-release-ships-a-full-materials-package`): текст карточки (для покупателя, по коду; про DaData бесплатно, ORS бесплатно, платные сервисы — честно; способы оплаты зарубежных сервисов — его текст, оставить место), changelog, обложка 1000×750 + иконка 512 (Codex `gpt-6.1-sol`, концепт сначала — но ночью его нет: сделать 2 варианта), галерея 1280×720 с рига (фейковый телефон), документация для новичка, черновики блога и TG — на `~/Desktop/woodev-publications/range-2.0.0/`.

## Operator morning

- **Ключи для живой проверки сервисов** (D4): Mapbox — у него есть; Google — «вроде был», где — не помнит; 2GIS — ключ поддержки 2021 г., лимит 1 000, может быть мёртв; Яндекс — нет; ORS — зарегистрировать (его почта). Ночью — только записанные фикстуры по документации; живые пробы — утром, с его ключами. Ключи НИКОГДА не печатать.
- **Measured s218 (05.11.2026)**: keys live in `~/Projects/woocommerce-range-shipping/.env` (gitignored, copied into worktrees via `.worktreeinclude`; names in `.env.example`). Mapbox token WORKS (Directions `driving` 11.76 km / `driving-traffic` 10.84 km, Red Square → Moscow City, response shape as §4.5). 2GIS key is DEAD: 403 `invalid_key` on Routing 7.0.0, Distance Matrix and Catalog geocode — 10-char legacy key; a new key from platform.2gis.ru is needed (operator, tomorrow, together with Google/Yandex/ORS).
- **Утром — взгляд на риг**: склады, «Расчёт расстояния», метод доставки, оба checkout, заказ, плюс пакет материалов на `~/Desktop/woodev-publications/range-2.0.0/`.

### Measured in s217

- 05.11.2026: тарифы 2GIS Routing API / Distance Matrix API — 6 700 ₽/мес за 10 000 (−10 % за год); Яндекс «Матрица расстояний» — 20 800 ₽/мес за 1 000/сутки, 195 000 ₽/год (226 200 расширенная) — прочитано со страниц тарифов curl'ом.
- 05.11.2026: фреймворк уже даёт вес по умолчанию `Shipping\Settings\Default_Dimensions_Settings` (встроенный 100 г, им пользуется упаковщик, `box-packer/class-wc-packer-dispatcher.php:233-249`).
- 05.11.2026: на риге выключены фикстуры `woodev-test-plugin`, `woodev-realistic-shipping-plugin`, `woodev-test-shipping-method`; Ozon без ключа на риге = mu-plugin рига (58107 в его списке).
- Мак: `local-ci` зелёный на релизных головах Ozon/Yandex/«Отслеживания» (04.11.2026); нужны `LOCAL_CI_DOCKER_HOST` (OrbStack) и `run-local-ci run -w <файл>`.

## Traps

(1) Codex без Serena не пишет PHP: писатель Sonnet/Haiku, Codex — критик и картинки. (2) `worker-start --base-branch origin/main` → `repo_not_found`: worktree через `orca worktree create --no-parent`. (3) **Никогда `cat .wp-env.override.json`** — в нём тестовые ключи (s215 напечатал их в транскрипт). (4) Цену из отчёта воркера сверять со страницей поставщика: в s217 2GIS 6 700 ₽ была взята не из той строки (совпало случайно). (5) e2e фреймворка требует включить три фикстуры рига (`wiki/local-rig.md`). (6) Codex-воркер в Orca получает handle `structworker_…` — watchdog с ним работает.

## Related

- [v2 spec](../specs/2026-11-05-range-shipping-v2-design.md)
- [session s217](../sessions/s217.md)
