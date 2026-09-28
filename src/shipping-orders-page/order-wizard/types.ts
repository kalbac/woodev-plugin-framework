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
	shipping_line: Record<string, unknown> | null;
	pickup_point: Record<string, unknown> | null;
	fields: Record<string, unknown>;
	carrier_fields: Record<string, unknown>;
	payment_method: string;
	/** '' = the server's default (`pending` on create, «keep» on update). */
	status: string;
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

/** `POST` / `PUT` success body. */
export interface SaveResult {
	id: number;
	number: string;
	message: string;
}

/** The five steps, in order (O6). */
export const WIZARD_STEPS = [ 'customer', 'address', 'items', 'delivery', 'payment' ] as const;

export type WizardStepId = typeof WIZARD_STEPS[ number ];
