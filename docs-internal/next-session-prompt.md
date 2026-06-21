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

## ✅ B-2 forward-tolerance — РЕШЕНО (обсуждено с оператором s27)

Классы фреймворка **всегда грузятся из старшей зарегистрированной копии** для всего парка (резолвер регистрирует автозагрузчик на путь победителя-по-версии), независимо от того, кто выиграл bootstrap-rendezvous. Поэтому новый плагин не ломается против старого победителя rendezvous; под риском — старый плагин против нового фреймворка, и его прикрывает существующий guard `backwards_compatible` (`resolver:148-153`: деактивация-с-варнингом плагина ниже минимума загруженной копии — как в v1). Протокол с `capabilities` никогда не релизился → сломать развёрнутый плагин не может. Два правила закреплены письменно в `AGENT-RULES.md` Rule 3: каждое определение задаёт `version` + `backwards_compatible`; контракт регистрации additive-only с v2.0.0.

## 🎯 Кандидаты на следующую задачу (выбор оператора)

1. **🚧 Shipping module (главный кусок, нужно участие оператора).** PLANS §3.2 — «идеальным и максимально универсальным». Скелет богатый, но **ни разу не проверен реальным плагином**. Конкретные дыры (аудит s27): `admin/views/html-admin-shipping-method-status.php` — заглушка 30%; нет setup-wizard; **нет абстракции label/export**; JS/CSS не верифицированы; webhook-handler не проверен на yandex. План: conformance-аудит против 3 референс-плагинов (`woocommerce-edostavka`, `woodev-russian-post`, `woocommerce-yandex-delivery` — последний эталон ПВЗ) → закрыть дыры → пилотная миграция yandex как доказательство универсальности. Хорошо ложится на **autodev-loop**.
2. **payment-gateway trait extraction** (`class-payment-gateway.php` ~3 542 строки) — классический autodev-loop кандидат. Godaddy borrow: `Block_Integration_Trait`, `Enum_Trait`+псевдо-энумы (спека §9).
3. **Review #4** — `array()`→`[]` (~797) + типы + `@since` sweep + enforce `Generic.Arrays.DisallowLongArraySyntax`. + OB-6 dead-file sweep. autodev-loop.
4. **box-packer добивка** (наименее срочно): non-WC wrapper (S), или настоящая минимальная упаковка (грид-эвристика уже стоит, не оптимальна).

## Гигиена

`@since 2.0.2`. Публичные `docs/` не трогаем (s13). Серена есть; для существующих PHP — встроенный `Edit` (Serena `replace_content` ломает EOL на Windows). JS/SCSS не пиннятся к LF → `sed -i 's/\r$//'` после Edit. **Composer только в dev/тестах, не в плагинах.** После добавления класса фреймворка — `php bin/generate-class-map.php`. Мерж: ветка → PR → зелёный CI (проверь, что unit-матрица реально прошла, а не скипнулась за гейтом) → `--squash --delete-branch` (НЕ `--auto`). Codex-ревью на архитектурно/security-чувствительное; находки не автофиксить вне автономного режима — спрашивать.
