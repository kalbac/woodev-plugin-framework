# Промт для следующей сессии (s9): S3.3 — built-in webhooks + §4 Ed25519 signing

> Написан в s8 (2026-06-11) после мерджа S3.2 (PR #31 `f7d29f3`). Скопируй этот текст в новую сессию как стартовый бриф. После реализации — удали этот файл.

---

Проект: **Woodev Plugin Framework** (`D:\projects\woodev_framework`). Реализовать **S3 sub-stage 3 — built-in webhooks** по спеке `docs-internal/platform-v2-s3-licensing-webhooks-spec.md` **вместе с §4 Ed25519 signing** из `platform-v2-s3-licensing-need-license-spec.md` (включая B-3 rework апдейтера). Направление и операторские решения **залочены — НЕ переоткрывать**; протокольные детали §9 — решить в этой сессии (они блокирующие).

## ШАГ 0 — гигиена

1. Свежая ветка `feat/s3-licensing-webhooks` от свежего `main` (сейчас `ea8f0f8`).
2. `composer check` зелёный на базовой линии: **337 unit-тестов / 1107 assertions**. JS-сборку не трогаешь (если не меняешь `src/` — assets-parity job и так зелёный).
3. Прочитать ПЕРЕД планированием: `platform-v2-program-tracker.md` → `platform-v2-s3-licensing-webhooks-spec.md` (целиком, §9 — наизусть) → `platform-v2-s3-licensing-need-license-spec.md` §4 (примитив, normalize_site, test vector, B-3) → `GOTCHAS.md` (минимум: licensing/two-layer, framework/includes-wiring, testing/unit ×2, testing/integration rest-cookie, i18n/russian-source-plural-n, build/pr-conflict-skips-ci) → `platform-v2-execution-protocol.md`.

## Workflow (как в s8 — обязателен)

1. `superpowers:writing-plans` по обеим спекам → план `platform-v2-s3-licensing-webhooks-plan.md` (зафиксировать в нём решения по всем 9 пунктам §9 ДО кода).
2. Разложить atomic-задачи в `.autodev/queue/pending/` по §8 спеки (формат как `done/s6-p*.md`, frontmatter `model:`):
   - **s8-p0** (добавить к §8): §4 signing consumption — `is_license_required()` читает подписанный server-claim вместо литерала `true` (outage-grace: нет/просрочен claim в 14-дневном окне → last-known-good, default true) + **B-3**: `load_updater()` всегда конструирует апдейтер (keyless polling), выровнять admin/cron-гейты, regression-тест «нет ключа → апдейтер сконструирован → claim потреблён». Tier: opus. **Идёт первым** — pull-fallback (p5) без B-3 не имеет транспорта для keyless-плагинов.
   - **s8-p1** generic Ed25519 envelope verifier + `woodev_normalize_site()` (общий для §4-claims и webhooks; §4-верификатор = тонкая обёртка) — opus;
   - **s8-p2** nonce store + command dispatcher (атомарный claim §9.1, границы стора §9.2, forward tolerance) — opus;
   - **s8-p3** REST `woodev/v1/license-command` через существующий `Woodev_REST_V1_Registrar` (election §9.3, abuse-контроли §9.4) — sonnet;
   - **s8-p4** `deactivate_plugin` handler + notice + hook + log (multisite network-active → reject) — sonnet;
   - **s8-p5** pull-fallback + acks (ack-lifecycle §9.5, структура §9.6, аутентичность §9.7) — sonnet;
   - **s8-p6** holistic critic + contract freeze §5 → INVARIANTS (§9.8) — оркестратор.
3. **Autodev-pattern для всего существенного**: worker-субагент пишет дифф → **адверсариальный критик = GPT-5.5 high** (`prompt | codex exec -m gpt-5.5 -c model_reasoning_effort="high" -s read-only`; при rate-limit — стенд-ин Opus, отметить в коммите) ревьюит КАЖДЫЙ контракт-смежный дифф И твои in-place фиксы (no self-certify) → commit. Холистический критик по всей фиче в конце. `composer check` зелёный перед КАЖДЫМ коммитом.

## Залоченные решения (не переоткрывать)

- **D-W1** v1-команда = только `deactivate_plugins(...)`, файлы НЕ удаляются; `delete_plugin` → `unsupported_command`.
- **D-W2** один общий эндпоинт `POST woodev/v1/license-command`, таргет по подписанному `plugin_id`; регистрирует версионно-арбитражная копия через `Woodev_REST_V1_Registrar`.
- **D-W3** pull-fallback в v1: `license_commands[]` в ответах weekly check + updater; ack через `consumed_command_nonces` в следующем плановом запросе.
- **D-W4** envelope расширяемый, но v1 реализует только лицензионные команды; диагностика отложена.
- Криптопримитив §4 без изменений: Ed25519 `sodium_crypto_sign_verify_detached`, canonical JSON (рекурсивный ksort, `JSON_UNESCAPED_SLASHES|UNICODE`), `woodev_normalize_site()` байт-в-байт с обеих сторон, опубликованный test vector.
- `permission_callback => '__return_true'` на эндпоинте — аутентификация ЕСТЬ подпись (нет WP-пользователя в этом флоу); подпись проверяется ДО любых lookup'ов.

## Жёсткие правила

- **Anti-pirate инвариант**: webhooks действуют через команды, НИКОГДА не флипают локальное license-состояние; `is_license_valid()`/`is_active()` не зависят от `is_need_license()`. Сервер — единственный авторитет.
- **Новые контракты §5 замораживаются при имплементации** (route, `woodev_license_command_nonces`, `woodev_{plugin_id}_remote_deactivated`, `license_commands`/`consumed_command_nonces`/ack-словарь) — parity-тесты пиняют строки. Существующие контракты — байт-в-байт.
- Любой провал верификации → reject без side effects; неотличимые rejection-ответы; нотификации/i18n — русские строки, **НИКАКИХ `_n()`** (count-neutral формулировки).
- Тесты: unit-матрица §7 + дополнения §9.9 (concurrency double-execution, crash-between-claim-and-action, mixed-fleet window, duplicate download id, network-active multisite, oversized body, lost ack + redelivery) + **integration в wp-env** для REST-эндпоинта (помни семантику `rest_cookie_check_errors` — тут её НЕТ, эндпоинт публичный; см. гочу). Reflection — guard `PHP_VERSION_ID < 80100`; Brain-Monkey function pollution — стабы в хелперах, `@runInSeparateProcess` для function-absence.
- Conventional Commits; новые файлы — `require_once` в `includes()` (гоча framework/includes-wiring); class_exists-гарды для multi-version.

## Кросс-репо шаги

- **PROD-pubkey woodev-core**: для тестов — опубликованный test vector + тестовые ключи. Вшивание PRODUCTION-ключа — операторский шаг (wp eval-сниппет в woodev-core-спеке в `D:\Projects\woodev_theme\docs\superpowers\specs\2026-06-10-woodev-core-license-authority-signing-spec.md`); запросить у оператора, когда дойдёшь до константы, остальную работу не блокировать (placeholder + явный TODO-тест «ключ не placeholder» можно пометить skipped до получения).
- **Зеркальная server-спека** (§6: очередь команд per (site,plugin), выдача, ack-consumption, подпись тем же `License_Authority`-ключом) → новый файл в `D:\Projects\woodev_theme\docs\superpowers\specs\` + локальный коммит в woodev_theme (remote нет) — как в s7.

## Финал сессии

1. PR в `main`; **мердж ТОЛЬКО после зелёных GH Actions И решения оператора** (squash + delete-branch). gh авторизован (kalbac). Прямой пуш в main запрещён политикой — доки тоже через PR.
2. Обновить трекер (S3.3 → статус), удалить этот файл, по команде «сохрани сессию» — полный протокол.
