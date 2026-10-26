# Переход на версию 2: что меняется для разработчиков и интеграций (1С, CRM) — Почта России для WooCommerce

Статья для тех, кто подключал к магазину обмен с 1С, CRM или писал свой код под плагин «Почта России». Настройки магазина при обновлении переносятся сами; здесь только то, что может сломать внешний обмен или собственный код.

**Главное.** Изменились идентификаторы способов доставки (`method_id`). В отличие от других плагинов Woodev, обновление Почты России **переписывает и строки доставки уже оформленных заказов**. Часть хуков и адресов версии 1 удалена.

## Идентификаторы способов доставки

| Было (версия 1) | Стало (версия 2) | Правило |
|---|---|---|
| `wc_russian_post_courier` | `russian_post_courier` | без условий |
| `wc_russian_post_postal` | `russian_post_pickup` | в зоне доставки; способ переходит один в один |
| `wc_russian_post_postal` | `russian_post_postamat` | только в строках старых заказов, где сохранён пункт-постамат |
| `wc_russian_post_postal` | `russian_post_international` | только в строках старых заказов с адресом доставки не в России и без пункта выдачи |
| — | `russian_post_postamat`, `russian_post_international` | новые способы; в зонах доставки их нужно добавить самостоятельно, обновление этого не делает |

Что делает обновление с зонами: способ сохраняет зону и номер экземпляра (`instance_id`), меняется только идентификатор. Настройки копируются под новое имя опции: `woocommerce_wc_russian_post_courier_<id>_settings` → `woocommerce_russian_post_courier_<id>_settings`, `woocommerce_wc_russian_post_postal_<id>_settings` → `woocommerce_russian_post_pickup_<id>_settings`; старые опции остаются на случай отката. В настройках оплаты при получении («Доступно для способов доставки») идентификаторы заменяются так же; прежние значения сохранены в журнале обновления (опция `wc_russian_post_upgrade_2_0_0`).

## Старые заказы: идентификаторы переписываются

> **Внимание.** После обновления плагин в фоне (через планировщик действий WooCommerce, задача `woodev_wc_russian_post_migrate_orders_batch`) проходит по заказам и заменяет в строке доставки `wc_russian_post_courier` и `wc_russian_post_postal` на новые идентификаторы из таблицы выше. Прежний идентификатор сохраняется в скрытом мета-поле строки доставки `_wc_russian_post_v1_method_id`. Переход идёт пачками, поэтому какое-то время после обновления часть заказов ещё содержит старые значения, часть — новые.

Для обмена это значит:

- сопоставление по `wc_russian_post_courier` и `wc_russian_post_postal` перестанет находить заказы по мере переноса — перенастройте на `russian_post_*`;
- пока перенос не закончен, принимайте оба набора значений;
- если нужен исходный идентификатор, читайте `_wc_russian_post_v1_method_id`.

Вместе с идентификатором для старых заказов заполняются новые мета-поля заказа `_wc_russian_post_pickup_point` (выбранный пункт) и `_wc_russian_post_product_key` (вид отправления), а сохранённая запись о тарифе `wc_russian_post_rate` в строке доставки переводится в простой массив.

## Мета-данные заказа

Ключи, которые читают обмены, не изменились:

| Ключ | Что хранит |
|---|---|
| `_wc_russian_post_status` | статус: `new`, `exported`, `accepted`, `dispatched`, `failed`, `delivered`, `canceled`; в версии 2 добавлены `ready`, `returning`, `returned` |
| `_wc_russian_post_tracking_number` | трек-номер (ШПИ) |
| `_wc_russian_post_russian_post_id` | идентификатор заказа в «Отправке» |
| `_wc_russian_post_export_type` | способ выгрузки (`otpravka` или `widget`) |
| `_wc_russian_post_tracking_history`, `_wc_russian_post_latest_order_update_time` | история отслеживания и время последнего опроса |
| `_wc_russian_post_tracking_number_email_sent` | письмо с трек-номером уже отправлено (учитывается, повторно не шлётся) |

Новые поля версии 2: `_wc_russian_post_pickup_point`, `_wc_russian_post_pickup_point_id`, `_wc_russian_post_pickup_point_address`, `_wc_russian_post_product_key`, `_wc_russian_post_dimension_type`, `_wc_russian_post_group_name`, `_wc_russian_post_address_doubtful`.

Настройки плагина остаются в опции `woocommerce_wc_russian_post_settings` с прежними именами полей. Часть полей версия 2 больше не использует (например, логин и пароль отслеживания по SOAP: вместо них нужен новый токен отслеживания, он запрашивается при обновлении), но значения не удаляются.

## Хуки

Плагин не показывает уведомлений о удалённых хуках: код на них просто перестаёт срабатывать.

**Сохранились**

| Хук | Аргументы |
|---|---|
| действие `wc_russian_post_order_status_changed` | `($old, $new, $order_id)`, без изменений; статусы — свои, из таблицы выше |
| действия `wc_russian_post_order_status_changed_from_{старый}_to{новый}` (без подчёркивания перед новым статусом — как в версии 1) и `wc_russian_post_order_status_changed_to_{статус}` | `($order_id)`, без изменений |
| действие `wc_russian_post_order_tracking_number_changed` | `($tracking_number, $order_id)`, без изменений |

**Сохранилось имя, изменились аргументы**

```php
// Было (версия 1): $request — запрос, $export_type — 'otpravka' или 'widget',
// третий аргумент — внутренний объект заказа плагина.
// Стало (версия 2): ответ сервиса, всегда 'otpravka', обычный WC_Order.
add_action( 'wc_russian_post_success_exported_order', function ( $response, $export_type, $order ) {
	// $order->get_id(), $order->get_meta( '_wc_russian_post_tracking_number' )
}, 10, 3 );
```

**Удалены без замены**

`wc_russian_post_after_order_update`, `wc_russian_post_parcel_types`, `wc_russian_post_method_title`, `wc_russian_post_formatted_address`, `wc_russian_post_shipping_rate_cart_total`, `wc_russian_post_cart_content_total`, `wc_russian_post_checkout_script_params`, `wc_russian_post_suggestions_plugin_default_params`, `wc_russian_post_additional_info_strings`, `wc_russian_post_need_reload_shipping_methods`, `wc_russian_post_order_statuses`, `wc_russian_post_allow_country_codes`, `wc_russian_post_clear_address_part`, `wc_russian_post_clear_address_part_patterns`, `wc_russian_post_tracking_url`, `wc_russian_post_order_needs_insurance_cost`, `wc_russian_post_order_otpravka_export_data`, `wc_russian_post_order_otpravka_export_item_data`, `wc_russian_post_order_otpravka_translate_fields`, `wc_russian_post_order_widget_export_data`, `wc_russian_post_disabled_statuses_for_export`, `wc_russian_post_disabled_statuses_for_complete`, `wc_russian_post_disabled_statuses_for_delivering`, `wc_russian_post_disabled_statuses_for_canceled`, `wc_russian_mail_category`, `wc_russian_post_tracking_update_interval`, `wc_russian_post_max_orders_per_update`.

Хуки расчёта стоимости (`wc_russian_post_rates_item`, `wc_russian_post_rate_cost`, `wc_russian_post_rate_calculate_shipping`) и `wc_russian_post_integration_form_fields` в версии 2 не вызываются. Аналоги общего модуля — `woodev_shipping_method_calculated_rate($rate, $package, $method)`, `woodev_shipping_method_after_calculate_shipping($package, $rate, $method)` и `woodev_shipping_plugin_settings_{id}_form_fields` — подходят по смыслу, но с другими аргументами.

Фильтр `woocommerce_shipping_{method_id}_is_available` строится из идентификатора способа, поэтому код, подписанный на `wc_russian_post_courier` или `wc_russian_post_postal`, больше не срабатывает.

## Адреса, AJAX и расписание

- Действия `admin-ajax.php` `get_post_office`, `set_russian_post_point`, `russian_post_order_action`, `russian_post_set_customer_location` удалены (первое было открытым прокси к «Отправке»).
- Адреса `?wc-api=russian_post_barcode` и `?wc-api=russian_post_status` удалены вместе с кабинетом «Виджет».
- Страница заказов `wc_russian_post_orders` сохранена.
- Задача `wc_russian_post_orders_update` (раз в 12 часов) при обновлении отменяется; отслеживание ведёт задача `woodev_wc_russian_post_poll_tracking`. В версии 2 также появились хуки `woodev_wc_russian_post_customs_declaration`, `woodev_wc_russian_post_no_declaration_countries` и `woodev_wc_russian_post_tracking_daily_cap`; это новые точки расширения, а не замены хуков версии 1.

## Что проверить в обмене с 1С

1. Принимает ли обмен значения `russian_post_courier`, `russian_post_pickup`, `russian_post_postamat`, `russian_post_international` — и, на время переноса, старые `wc_russian_post_courier` и `wc_russian_post_postal`.
2. Когда пройдёт перенос старых заказов; до этого сверка по старому идентификатору ещё работает частично.
3. Выгрузка трек-номера: `_wc_russian_post_tracking_number` на месте.
4. Если обмен брал пункт выдачи из данных тарифа (`wc_russian_post_rate`), берите `_wc_russian_post_pickup_point`.
5. Свой код на `wc_russian_post_*`: сверьте со списками, прогоните на тестовой копии.
6. Тестовые заказы со всеми способами, какой `method_id` попал в выгрузку.
