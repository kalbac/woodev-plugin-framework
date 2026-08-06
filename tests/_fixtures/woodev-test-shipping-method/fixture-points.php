<?php
/**
 * Rig fixture data for Woodev_Test_Bulk_Point_Source::all_points().
 *
 * WHY this file exists: the pickup-map presentation rework (SP-map, 20-task plan)
 * needs a fixture rich enough to drive its map/panel surfaces on the local rig, and
 * a 5-point single-type fixture cannot do it. Specifically, without this data:
 *  - the type filter has nothing to filter (needs >= 2 distinct `type.code` values);
 *  - the co-located-points tab bar never triggers (needs two points sharing one
 *    exact lat/lng — a PVZ and a POSTAMAT in the same building, a real CDEK case);
 *  - the map never shows a cluster badge and the sidebar never needs to scroll
 *    (both need dozens of points, not five);
 *  - the point card's "select" button is never seen disabled (needs a point that
 *    refuses cash on delivery);
 *  - the point card's optional sections (services, phone, weight limit) are never
 *    seen in both their present AND absent states.
 *
 * This file is `require`d directly by `Woodev_Test_Bulk_Point_Source::all_points()`
 * (see woodev-test-shipping-method.php) and by
 * tests/unit/Shipping/Pickup/TestFixturePointsTest.php, which asserts every shape
 * above is actually present. It intentionally contains only plain arrays — no class
 * references — so it can be `require`d standalone without loading the plugin.
 *
 * The FIRST FIVE entries below are byte-identical to the fixture's original 5
 * points, ids included (`FIX-BULK-1`..`FIX-BULK-5`) — rig state and older session
 * notes reference these ids by name, so they must not move or change shape.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------------
// The original 5 points — byte-identical, ids included. `services` is added
// (empty, except FIX-BULK-3 and FIX-BULK-5 below) since every payload in this
// file now carries that key.
// -----------------------------------------------------------------------------

$original_five = [
	[
		'id'              => 'FIX-BULK-1',
		'name'            => 'ПВЗ «Тверская»',
		'lat'             => 55.7602,
		'lng'             => 37.6055,
		'address'         => 'Москва, ул. Тверская, д. 5',
		'short_address'   => 'Тверская, 5',
		'locality'        => 'Москва',
		'postal_code'     => '125009',
		'phone'           => '+7 495 100-00-01',
		'instruction'     => 'Вход со двора, 2 этаж.',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [],
	],
	[
		// = Woodev_Test_Bulk_Point_Source::COD_REFUSING_POINT_ID (literal — this file
		// is required standalone and must not reference the class).
		'id'              => 'FIX-BULK-2',
		'name'            => 'ПВЗ «Арбатская — без оплаты при получении»',
		'lat'             => 55.7522,
		'lng'             => 37.5994,
		'address'         => 'Москва, ул. Арбат, д. 12',
		'short_address'   => 'Арбат, 12',
		'locality'        => 'Москва',
		'postal_code'     => '119002',
		'phone'           => '+7 495 100-00-02',
		'instruction'     => 'Отдельный вход с торца здания.',
		'work_time'       => 'Пн-Сб 10:00-20:00',
		'payment_methods' => [ 'card' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => false,
		'max_weight'      => null,
		'services'        => [],
	],
	[
		'id'              => 'FIX-BULK-3',
		'name'            => 'ПВЗ «Красная Пресня»',
		'lat'             => 55.7607,
		'lng'             => 37.5717,
		'address'         => 'Москва, ул. Красная Пресня, д. 24',
		'short_address'   => 'Красная Пресня, 24',
		'locality'        => 'Москва',
		'postal_code'     => '123242',
		'phone'           => '+7 495 100-00-03',
		'instruction'     => 'Цоколь, вход со стороны парковки.',
		'work_time'       => 'Пн-Вс 08:00-22:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [ 'Примерка', 'Проверка вложений', 'Частичный выкуп' ],
	],
	[
		// = Woodev_Test_Bulk_Point_Source::WEIGHT_LIMITED_POINT_ID (literal — see above).
		'id'              => 'FIX-BULK-4',
		'name'            => 'ПВЗ «Сокольники — лимит 1 кг»',
		'lat'             => 55.7887,
		'lng'             => 37.6789,
		'address'         => 'Москва, ул. Сокольнический Вал, д. 8',
		'short_address'   => 'Сокольнический Вал, 8',
		'locality'        => 'Москва',
		'postal_code'     => '107113',
		'phone'           => '+7 495 100-00-04',
		'instruction'     => 'Небольшой пункт — только лёгкие посылки.',
		'work_time'       => 'Пн-Пт 10:00-19:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => 1000,
		'services'        => [],
	],
	[
		'id'              => 'FIX-BULK-5',
		'name'            => 'ПВЗ «Строгино»',
		'lat'             => 55.8027,
		'lng'             => 37.4092,
		'address'         => 'Москва, Строгинский б-р, д. 15',
		'short_address'   => 'Строгинский б-р, 15',
		'locality'        => 'Москва',
		'postal_code'     => '123592',
		'phone'           => '+7 495 100-00-05',
		'instruction'     => '',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [ 'Примерка' ],
	],
];

// -----------------------------------------------------------------------------
// ~35 additional PVZ points spread across Moscow (lat 55.60-55.90, lng 37.35-37.85)
// on a 7x5 grid, so the map clusters at city zoom and the sidebar list scrolls.
// Streets are real Moscow streets, none reused from the 5 points above or from
// Woodev_Test_Viewport_Point_Source's fixture data.
// -----------------------------------------------------------------------------

$grid_streets = [
	'Ленинский проспект',
	'Кутузовский проспект',
	'Профсоюзная улица',
	'Каширское шоссе',
	'Варшавское шоссе',
	'Волгоградский проспект',
	'Рязанский проспект',
	'Щёлковское шоссе',
	'Открытое шоссе',
	'Дмитровское шоссе',
	'Ленинградское шоссе',
	'Ярославское шоссе',
	'Хорошёвское шоссе',
	'Можайское шоссе',
	'Севастопольский проспект',
	'Нахимовский проспект',
	'Люблинская улица',
	'Новокосинская улица',
	'Первомайская улица',
	'Измайловский проспект',
	'Сиреневый бульвар',
	'Бауманская улица',
	'Таганская улица',
	'Пятницкая улица',
	'Большая Ордынка',
	'Мясницкая улица',
	'Покровка',
	'Солянка',
	'Земляной Вал',
	'Садовая-Кудринская улица',
	'Проспект Мира',
	'Автозаводская улица',
	'Шаболовка',
	'Ленинская Слобода',
	'Новый Арбат',
];

// payment_methods rotate through three combinations so the fixture varies them.
$grid_payment_methods = [ [ 'card' ], [ 'cod' ], [ 'card', 'cod' ] ];

// Services are populated on exactly 5 of the 35 grid points (index 0, 5, 10, 15, 20)
// so the fixture covers both the present and absent state at scale, on top of the
// single already-populated original point (FIX-BULK-3/5 above).
$grid_services_by_index = [
	0  => [ 'Примерка', 'Частичный выкуп' ],
	5  => [ 'Проверка вложений' ],
	10 => [ 'Примерка' ],
	15 => [ 'Частичный выкуп', 'Проверка вложений' ],
	20 => [ 'Примерка', 'Частичный выкуп', 'Проверка вложений' ],
];

// Exactly 3 of the 35 grid points carry no phone number.
$grid_indices_without_phone = [ 2, 14, 26 ];

$grid_points = [];

foreach ( $grid_streets as $index => $street ) {
	$lat = round( 55.60 + ( $index % 7 ) * ( 0.30 / 6 ), 4 );
	$lng = round( 37.35 + intdiv( $index, 7 ) * ( 0.50 / 4 ), 4 );

	$house_number = ( $index * 2 ) + 3;
	$point_number = $index + 6; // Continues numbering after FIX-BULK-5.

	$grid_points[] = [
		'id'              => "FIX-BULK-{$point_number}",
		'name'            => "ПВЗ «{$street}»",
		'lat'             => $lat,
		'lng'             => $lng,
		'address'         => "Москва, {$street}, д. {$house_number}",
		'short_address'   => "{$street}, {$house_number}",
		'locality'        => 'Москва',
		'postal_code'     => sprintf( '1%05d', 10000 + $point_number ),
		'phone'           => in_array( $index, $grid_indices_without_phone, true )
			? ''
			: sprintf( '+7 495 100-00-%02d', $point_number ),
		'instruction'     => 'Вход с улицы, вывеска у двери.',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => $grid_payment_methods[ $index % 3 ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => $grid_services_by_index[ $index ] ?? [],
	];
}

// -----------------------------------------------------------------------------
// 6 POSTAMAT points — a second point type, so the type filter has something to
// filter. Postamats are self-service parcel lockers: no cash on delivery, and a
// realistic per-slot weight cap.
// -----------------------------------------------------------------------------

$postamat_locations = [
	[ 'Ходынский бульвар', 55.7823, 37.5286 ],
	[ 'Кожуховская улица', 55.7061, 37.6889 ],
	[ 'Ходынская улица', 55.7789, 37.5106 ],
	[ 'Пресненская набережная', 55.7495, 37.5378 ],
	[ 'Комсомольский проспект', 55.7276, 37.5843 ],
	[ 'Ботанический сад', 55.8267, 37.6472 ],
];

$postamat_points = [];

foreach ( $postamat_locations as $index => [ $street, $lat, $lng ] ) {
	$point_number = $index + 1;

	$postamat_points[] = [
		'id'              => "FIX-BULK-POSTAMAT-{$point_number}",
		'name'            => "Постамат «{$street}»",
		'lat'             => $lat,
		'lng'             => $lng,
		'address'         => "Москва, {$street}",
		'short_address'   => $street,
		'locality'        => 'Москва',
		'postal_code'     => sprintf( '1%05d', 20000 + $point_number ),
		'phone'           => '',
		'instruction'     => 'Ячейка автоматической выдачи, код из SMS.',
		'work_time'       => 'Круглосуточно',
		'payment_methods' => [ 'card' ],
		'photos'          => [],
		'type'            => [ 'code' => 'POSTAMAT', 'label' => 'Постамат' ],
		'accepts_cod'     => false,
		'max_weight'      => 15000,
		'services'        => [],
	];
}

// -----------------------------------------------------------------------------
// A co-located pair on IDENTICAL coordinates — models a real CDEK case: a pickup
// point and a postamat sharing one building. Drives the co-located-points tab bar.
// -----------------------------------------------------------------------------

$colocated_points = [
	[
		'id'              => 'FIX-BULK-COLOCATED-PVZ',
		'name'            => 'ПВЗ «Замоскворечье»',
		'lat'             => 55.7415,
		'lng'             => 37.6156,
		'address'         => 'Москва, ул. Большая Татарская, д. 9',
		'short_address'   => 'Большая Татарская, 9',
		'locality'        => 'Москва',
		'postal_code'     => '115184',
		'phone'           => '+7 495 100-00-90',
		'instruction'     => 'Пункт выдачи и постамат в одном здании — вход общий.',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [ 'Примерка' ],
	],
	[
		'id'              => 'FIX-BULK-COLOCATED-POSTAMAT',
		'name'            => 'Постамат «Замоскворечье»',
		'lat'             => 55.7415,
		'lng'             => 37.6156,
		'address'         => 'Москва, ул. Большая Татарская, д. 9',
		'short_address'   => 'Большая Татарская, 9',
		'locality'        => 'Москва',
		'postal_code'     => '115184',
		'phone'           => '',
		'instruction'     => 'Пункт выдачи и постамат в одном здании — вход общий.',
		'work_time'       => 'Круглосуточно',
		'payment_methods' => [ 'card' ],
		'photos'          => [],
		'type'            => [ 'code' => 'POSTAMAT', 'label' => 'Постамат' ],
		'accepts_cod'     => false,
		'max_weight'      => 15000,
		'services'        => [],
	],
];

// -----------------------------------------------------------------------------
// One deliberately long address (>= 80 chars via mb_strlen) — drives the point
// card's address-ellipsis rendering.
// -----------------------------------------------------------------------------

$long_address_point = [
	[
		'id'              => 'FIX-BULK-LONG-ADDRESS',
		'name'            => 'ПВЗ «Тёплый Стан — длинный адрес»',
		'lat'             => 55.6423,
		'lng'             => 37.5065,
		'address'         => 'Москва, Юго-Западный административный округ, район Тёплый Стан, ' .
			'улица Академика Виноградова, дом 14, корпус 3, строение 2, вход со стороны детской площадки',
		'short_address'   => 'Академика Виноградова, 14 к3 с2',
		'locality'        => 'Москва',
		'postal_code'     => '117623',
		'phone'           => '+7 495 100-00-99',
		'instruction'     => 'Домофон, позвонить оператору за 10 минут.',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [],
	],
];

// -----------------------------------------------------------------------------
// SP-5 Task 13: three points wired to the fixture's
// `woodev_shipping_pickup_point_selection` filter (see the plugin init callback in
// woodev-test-shipping-method.php) — DEMO-PVZ-REFUSE always refuses on confirmation
// (the rig's remembered-refusal path), DEMO-PVZ-FAST forces an immediate close,
// overriding this fixture's own two-step default (close_on_select is left at the
// Pickup_Handler constructor's own `false` — the fixture never passes that
// argument), and DEMO-PVZ-REFRESH asks for a checkout refresh. Coordinates are
// distinct from every point above at well past 4 decimal places so all three are
// individually clickable on the rig map.
// `accepts_cod` is deliberately `true` and `max_weight` is deliberately `null` on
// all three: the demo behaviour comes from the domain filter overriding the verdict,
// not from Constraint_Checker independently refusing them for an unrelated reason.
// That is not decoration — the refresh branch was originally attached to
// FIX-BULK-POSTAMAT-1, whose own record carries `accepts_cod: false` /
// `payment_methods: [ 'card' ]`; with COD the rig's only enabled gateway,
// Constraint_Checker refused that point before the filter could matter, the CTA was
// dead and the click handler self-guarded, so no request ever left the browser and
// the branch was unreachable through genuine interaction (s52).
// -----------------------------------------------------------------------------

$domain_seam_points = [
	[
		'id'              => 'DEMO-PVZ-REFUSE',
		'name'            => 'ПВЗ «Каланчёвская — временно не принимает заказы»',
		'lat'             => 55.7150,
		'lng'             => 37.6600,
		'address'         => 'Москва, Каланчёвская улица, д. 11',
		'short_address'   => 'Каланчёвская, 11',
		'locality'        => 'Москва',
		'postal_code'     => '107078',
		'phone'           => '+7 495 100-00-97',
		'instruction'     => '',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [],
	],
	[
		'id'              => 'DEMO-PVZ-FAST',
		'name'            => 'ПВЗ «Люсиновская — мгновенное закрытие»',
		'lat'             => 55.6900,
		'lng'             => 37.5450,
		'address'         => 'Москва, Люсиновская улица, д. 4',
		'short_address'   => 'Люсиновская, 4',
		'locality'        => 'Москва',
		'postal_code'     => '115093',
		'phone'           => '+7 495 100-00-98',
		'instruction'     => '',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [],
	],
	[
		'id'              => 'DEMO-PVZ-REFRESH',
		'name'            => 'ПВЗ «Варшавское шоссе — обновление корзины»',
		'lat'             => 55.7040,
		'lng'             => 37.6210,
		'address'         => 'Москва, Варшавское шоссе, д. 9',
		'short_address'   => 'Варшавское шоссе, 9',
		'locality'        => 'Москва',
		'postal_code'     => '117105',
		'phone'           => '+7 495 100-00-96',
		'instruction'     => '',
		'work_time'       => 'Пн-Вс 09:00-21:00',
		'payment_methods' => [ 'card', 'cod' ],
		'photos'          => [],
		'type'            => [ 'code' => 'PVZ', 'label' => 'Пункт выдачи заказов' ],
		'accepts_cod'     => true,
		'max_weight'      => null,
		'services'        => [],
	],
];

return array_merge(
	$original_five,
	$grid_points,
	$postamat_points,
	$colocated_points,
	$long_address_point,
	$domain_seam_points
);
