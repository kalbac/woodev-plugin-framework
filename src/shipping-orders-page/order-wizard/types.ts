/**
 * Types of the admin order wizard (#710 D1, increment I4b of card #969).
 *
 * The payload shapes mirror `Order_Payload_Validator`'s documented transport contract
 * (`woodev/shipping-method/admin/orders/class-order-payload-validator.php`), and the
 * prefill shape mirrors `Order_Editor::build_prefill()` — read on the I3 branch, not
 * recalled. Steps ①–③ own customer / billing / shipping / items; everything the later
 * steps fill (④ delivery, ⑤ payment) travels in {@link WizardRest} untouched, so I5a / I5b
 * extend the state without reshaping it.
 *
 * @package woodev-plugin-framework
 */

/** The address keys the validator reads (`Order_Payload_Validator::ADDRESS_KEYS`). */
export interface WizardAddress {
	first_name: string;
	last_name: string;
	company: string;
	address_1: string;
	address_2: string;
	city: string;
	/** A WooCommerce STATE CODE when the country has regions, free text otherwise. */
	state: string;
	postcode: string;
	country: string;
	phone: string;
}

/** Billing is an address plus the email. */
export interface WizardBilling extends WizardAddress {
	email: string;
}

/** The customer block: an existing user, a guest, or a guest with «create an account» (O11). */
export interface WizardCustomer {
	/** 0 = guest. */
	id: number;
	create_account: boolean;
	/** Display text of the picked user; UI only, never sent. */
	label: string;
}

/** One order line. Quantity and price are strings while the manager types. */
export interface WizardItem {
	/** Stable React key — never the index, rows can be removed. */
	key: string;
	/** The existing order item's id on edit, 0 for a new line. */
	item_id: number;
	product_id: number;
	variation_id: number;
	name: string;
	quantity: string;
	/** Per unit, before tax; editable (O8). */
	price: string;
}

/**
 * What steps ④ / ⑤ own. Opaque to steps ①–③: it is loaded from a prefill and sent back
 * as it is, so an edit that never reaches those steps cannot lose their values.
 */
export interface WizardRest {
	/**
	 * The chosen rate exactly as the payload wants it (`Order_Payload_Validator::check_shipping_line()`):
	 * `method_id`, `instance_id`, `rate_id`, `label`, `cost` (the FINAL price — the rate's own, or the
	 * one the manager typed, O8) and the rate's `meta`. Step ④ owns it.
	 */
	shipping_line: Record<string, unknown> | null;
	/** `{ id }` plus display fields; sent as it is, the validator reads only `id`. */
	pickup_point: Record<string, unknown> | null;
	fields: Record<string, unknown>;
	/** The carrier's own field values by field id (D7) — sent as they are; the server checks them against the carrier's declaration. */
	carrier_fields: Record<string, unknown>;
	/**
	 * The definitions of the carrier fields the CHOSEN tariff asks for (from the rates answer), so «Далее»
	 * can check them without asking again. Empty until the rates have answered. UI only: never sent.
	 */
	carrier_schema: CarrierField[];
	payment_method: string;
	/** '' = the server's default (`pending` on create, «keep» on update). */
	status: string;
	/**
	 * «Сразу выгрузить перевозчику» (O9, D6): send the order to the carrier right after it is created.
	 * Create only — an edit never exports (O4), so an edit's state holds `false` and the box is not drawn.
	 * Sent as the top-level `export_now`, beside the order, never inside it.
	 */
	export_now: boolean;
	/**
	 * What the carrier's own rate costs, as text — the reference the editable delivery price is
	 * compared with («изменено», «вернуть цену тарифа»). '' = unknown (an order just loaded for
	 * edit, before the rates came back). UI only: never sent.
	 */
	rate_cost: string;
	/** Whether the chosen rate is a pickup one — the step checks it for a point. UI only: never sent. */
	rate_is_pickup: boolean;
	/**
	 * The tariffs are being asked for the current package. A tariff chosen against a PREVIOUS
	 * package is not confirmed yet, so «Далее» waits for the answer. UI only: never sent.
	 */
	rates_pending: boolean;
	/**
	 * What step ⑤ needs to ask whether the chosen pickup point still suits the payment method the
	 * manager picks AFTER it (D3: «⑤ re-validates»): the carrier whose picker config holds the points
	 * route, and the package weight in grams the last rates answer reported. `null` until the rates
	 * have answered. UI only: never sent.
	 */
	pickup_check: { provider: string; weight: number } | null;
}

/** The whole wizard state. */
export interface WizardData {
	customer: WizardCustomer;
	billing: WizardBilling;
	/** The DELIVERY address step ② edits; names / phone fall back to billing's at send time. */
	shipping: WizardAddress;
	/**
	 * True while the billing ADDRESS follows the delivery address (a new order always; an
	 * edited one only when the two matched on load). Billing names / email / phone are step
	 * ①'s and always sent as typed.
	 */
	billingFollowsShipping: boolean;
	items: WizardItem[];
	rest: WizardRest;
	/** The settlement the address search was scoped to (`within` for the street search); UI only. */
	settlementKey: string;
	/**
	 * The location record of the settlement the manager PICKED in step ②, whole and untouched
	 * (`Location_Record::to_array()` shape), or `null` when the city was typed by hand or the
	 * order was just loaded for edit. Step ④ hands it to the rates and pickup routes: a carrier
	 * that prices or lists points by its own settlement id needs it, and the typed fields alone
	 * cannot name one. Sent as `location`, never as part of the order payload.
	 */
	settlementRecord: Record<string, unknown> | null;
}

/** One problem the server reports — `data.errors` of a 422 (`Order_Editor::validation_error()`). */
export interface ServerError {
	/** Dotted path of the offending request field: `billing.email`, `items.0.quantity`… */
	field: string;
	code: string;
	message: string;
}

/** Messages grouped by field path. */
export type FieldErrors = Record<string, string[]>;

/** The order block a load returns (`Order_Editor::build_prefill()['order']`) — O14 reads it. */
export interface PrefillOrder {
	id: number;
	number: string;
	status: string;
	status_name: string;
	is_paid: boolean;
	total: string;
	currency: string;
}

/** `GET /shipping/orders/{id}/edit`: the payload's own shape plus the `order` block. */
export interface OrderPrefill {
	order: PrefillOrder;
	carrier: string;
	customer: { id: number; create_account: boolean };
	billing: Partial<WizardBilling>;
	shipping: Partial<WizardAddress>;
	items: Array<{
		item_id: number;
		product_id: number;
		variation_id: number;
		name: string;
		quantity: number;
		price: string;
	}>;
	shipping_line: Record<string, unknown> | null;
	pickup_point: Record<string, unknown> | null;
	fields: Record<string, unknown>;
	carrier_fields: Record<string, unknown>;
	payment_method: string;
	status: string;
}

/**
 * How the immediate export went (`Order_Editor_Controller::create_order()`, D6) — present on a create that asked
 * for it. `message` is one merchant-readable sentence: the carrier's own text prefixed with its name, or the
 * framework's words when it gave none. For the merchant only.
 */
export interface ExportOutcome {
	success: boolean;
	message: string;
}

/** `POST` / `PUT` success body. */
export interface SaveResult {
	id: number;
	number: string;
	/** For a create with an export, the whole sentence: «Заказ №N создан. …» or «Заказ №N создан, но не выгружен. СДЭК: …». */
	message: string;
	/** Only when the create asked for the export; the order exists either way. */
	export?: ExportOutcome;
}

/**
 * One field a carrier asks for under its tariff (O13, D7) — a `Field_Schema` entry (the very shape the
 * settings page's `ControlField` renders) plus the field's `id`. Declared in PHP; the plugin ships no JS.
 * Only the keys the wizard itself reads are named; the rest is `ControlField`'s.
 */
export interface CarrierField {
	/** The key of the value under `carrier_fields`. */
	id: string;
	name: string;
	/** The Settings API control (`number`, `select`, `toggle`…); null lets `ControlField` infer one. */
	controlType: string | null;
	/** The declared default — the value the field starts with. */
	value?: unknown;
	required?: boolean;
	show_if?: unknown;
	[ key: string ]: unknown;
}

/** One tariff, as `POST /shipping/orders/rates` returns it (`Admin_Rate_Calculator::format_rate()`). */
export interface RateOption {
	/** `method_id:instance_id` — the WooCommerce rate id. */
	id: string;
	method_id: string;
	instance_id: number;
	label: string;
	cost: number;
	delivery_time: string;
	description: string;
	is_pickup: boolean;
	/** The rate's own meta; copied onto the shipping item on save, as `WC_Checkout` does. */
	meta: Record<string, unknown>;
	/** The carrier's own fields for this tariff (D7); absent or empty when it asks for none. */
	order_fields?: CarrierField[];
}

/** One carrier's tariffs — a carrier with none for this package still appears, with an empty list. */
export interface RateGroup {
	/** The provider id — also the key of the carrier's pickup config in the bootstrap. */
	id: string;
	label: string;
	rates: RateOption[];
}

/** `POST /shipping/orders/rates` success body. */
export interface RatesResponse {
	destination: Record<string, string>;
	/** false when nothing in the order needs shipping. */
	needs_shipping: boolean;
	/** Order weight in GRAMS — what the pickup routes take as their explicit `weight`. */
	weight: number;
	zone: { id: number; name: string } | null;
	providers: RateGroup[];
}

/** The delivery-address fields the checkout's field policy has an opinion on. */
export type AddressFieldKey = 'country' | 'state' | 'city' | 'address_1' | 'address_2' | 'postcode';

/**
 * What the checkout's policy says about one field (`Checkout_Field_Policy::address_rules_for()`, #985):
 * `removed` — the checkout has no such field, so its value never reaches an order; `hidden` — the row is
 * not shown (removed, hidden by the country's locale, or hidden for a pickup tariff); `required` — the
 * customer must fill it, never true for a hidden one.
 */
export interface AddressRule {
	required: boolean;
	hidden: boolean;
	removed: boolean;
}

/** The whole delivery address's rules. */
export type AddressPolicy = Record<AddressFieldKey, AddressRule>;

/** `GET /shipping/orders/address-policy` success body. An empty `fields` means «no rule» (WooCommerce absent). */
export interface AddressPolicyResponse {
	country: string;
	pickup: boolean;
	fields: Partial<Record<AddressFieldKey, AddressRule>> | unknown[];
}

/** The five steps, in order (O6). */
export const WIZARD_STEPS = [ 'customer', 'address', 'items', 'delivery', 'payment' ] as const;

export type WizardStepId = typeof WIZARD_STEPS[ number ];
