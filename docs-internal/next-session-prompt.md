# Промт для следующей сессии (s28): после автозагрузчика — выбрать следующий крупный кусок

> Написан в s27 (2026-06-21, автономный ночной прогон). **s27 ПОЛНОСТЬЮ ЗАВЕРШЁН и зашиплен** (PR #78 `1aa4ec4` смержен в main): рантайм-автозагрузчик + тип плагина только через `extends`, `capabilities` удалён. Версия НЕ бампнута (2.0.1 unreleased, `@since 2.0.2`). Следующая задача — на выбор оператора; шиппинг ждёт его участия.

## Старт сессии

- framework: `docs-internal/CURRENT-STATE.md`, `docs-internal/GOTCHAS.md`, этот промт.
- `main` синхронизирован на `1aa4ec4`. **729 unit**, всё зелёное (phpstan L3 + phpcs чисто).

## Что зашиплено в s27 (контекст)

- `Woodev_Framework_Autoloader` — рукописный `spl_autoload` поверх генерируемой `woodev/class-map.php` (**Composer в плагинах НЕ используем**, только dev/test). Регистрируется резолвером на победившую копию до парсинга `extends`.
- Тип плагина = `extends` (`Woodev_Payment_Gateway_Plugin`/`Shipping_Plugin extends Woodev\Framework\Woocommerce_Plugin extends Woodev_Plugin`). `capabilities` удалён везде.
- `Woodev_Loader::register(__FILE__, [...])` — тонкий фасад entry-файла.
- `bin/generate-class-map.php` — **после добавления/переименования любого класса фреймворка перегенерировать карту** и закоммитить (гочи `framework-classmap-autoload-vendored-boot`).
- Спека/план: `docs-internal/specs|plans/2026-06-21-plugin-type-autoloader*`. Godaddy-разведка (OB-5) — в спеке §9.

## 🛑 ВАЖНО — pre-release блокер (B-2), обсудить до релиза v2

Удаление `capabilities` изменило loader-протокол. Если в смешанном v2-парке rendezvous выиграет **старая v2-копия** (её резолвер без автозагрузчика), `extends` нового плагина даст fatal. **Сейчас невозможно** (v2 не released, плагинов на старом протоколе нет), но **forward-tolerance (B-2) надо спроектировать до выпуска любого v2-плагина.** Это требует решения оператора (как версионировать/прощать loader-протокол; связано с будущей фазой версионных неймспейсов — спека §8).

## 🎯 Кандидаты на следующую задачу (выбор оператора)

1. **🚧 Shipping module (главный кусок, нужно участие оператора).** PLANS §3.2 — «идеальным и максимально универсальным». Скелет богатый, но **ни разу не проверен реальным плагином**. Конкретные дыры (аудит s27): `admin/views/html-admin-shipping-method-status.php` — заглушка 30%; нет setup-wizard; **нет абстракции label/export**; JS/CSS не верифицированы; webhook-handler не проверен на yandex. План: conformance-аудит против 3 референс-плагинов (`woocommerce-edostavka`, `woodev-russian-post`, `woocommerce-yandex-delivery` — последний эталон ПВЗ) → закрыть дыры → пилотная миграция yandex как доказательство универсальности. Хорошо ложится на **autodev-loop**.
2. **B-2 forward-tolerance** (см. блокер выше) — если хочешь снять риск до движения к релизу/неймспейсам.
3. **payment-gateway trait extraction** (`class-payment-gateway.php` ~3 542 строки) — классический autodev-loop кандидат. Godaddy borrow: `Block_Integration_Trait`, `Enum_Trait`+псевдо-энумы (спека §9).
4. **Review #4** — `array()`→`[]` (~797) + типы + `@since` sweep + enforce `Generic.Arrays.DisallowLongArraySyntax`. + OB-6 dead-file sweep. autodev-loop.
5. **box-packer добивка** (наименее срочно): non-WC wrapper (S), или настоящая минимальная упаковка (грид-эвристика уже стоит, не оптимальна).

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем (s13). Серена есть; для существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL на Windows). JS/SCSS не пиннятся к LF → `sed -i 's/\r$//'` после Edit. **Composer только в dev/тестах, не в плагинах.** После добавления класса фреймворка — `php bin/generate-class-map.php`. Мерж: ветка → PR → зелёный CI (проверь, что unit-матрица реально прошла, а не скипнулась за гейтом) → `--squash --delete-branch` (НЕ `--auto`). Codex-ревью на архитектурно/security-чувствительное; находки не автофиксить вне автономного режима — спрашивать.
