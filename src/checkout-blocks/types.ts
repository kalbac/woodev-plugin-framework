/**
 * Shared types of the Checkout Blocks locality chooser (SP-11 C-1, #1087).
 *
 * The wire shapes mirror the location REST layer (`Location_Controller`) and the `location` config
 * block (`Checkout_Config::build_location_block()`) — read from those, not recalled.
 *
 * @package woodev-plugin-framework
 */

/** A `{ name, type }` component of a location record (`Location_Record::parse_component_group()`). */
export interface LocationComponent {
	name: string;
	type?: string;
}

/** `Location_Record::to_array()` — round-tripped to `/location/select` verbatim, never rebuilt. */
export interface LocationRecord {
	key: string;
	provider_id: string;
	level: string;
	country: string;
	region?: LocationComponent | null;
	district?: LocationComponent | null;
	settlement?: LocationComponent | null;
	label?: string | null;
	ancestors?: string[];
	[ extra: string ]: unknown;
}

/** One `/location/suggest` entry. `label` is `esc_html()`-escaped by the server. */
export interface Suggestion {
	key: string;
	label: string;
	level: string;
	record: LocationRecord;
}

/** `{ key, level }` — the narrowed shape `current` and every `chain` entry carry. */
export interface ChainEntry {
	key: string;
	level: string;
}

export interface LevelSupport {
	region: boolean;
	settlement: boolean;
	address: boolean;
}

/** The `location` block the integration publishes (`Checkout_Config::build_location_block()`). */
export interface LocationConfig {
	endpoints: { suggest: string; select: string; list: string; forget: string };
	nonce: string;
	countries: string[];
	levels: Record< string, LevelSupport >;
	regionFieldRemoved: boolean;
	/**
	 * The merchant option «clear the street and postcode when the settlement changes»
	 * (`Location_Provider_Registry::SETTING_CLEAR_ADDRESS_ON_CHANGE`, default on). Absent — an older
	 * publication — means off: nothing is cleared unless the server said so.
	 */
	clearAddressOnChange?: boolean;
	current: ChainEntry | null;
	chain: Record< string, ChainEntry > | ChainEntry[];
	implicit: boolean;
	savedCityUnresolved: string | null;
	/**
	 * The settlement the customer EXPLICITLY chose and the server still holds (Blocks only —
	 * `Checkout_Handler::locality_blocks_config()`), as a full record so the client can check it
	 * against the native address before it claims a selection. `null` for no record or an implicit one.
	 */
	selection?: { record: LocationRecord } | null;
	/** Server-side delivery mode, checked against WooCommerce's forcedBillingAddress setting. */
	billingOnly?: boolean;
	i18n: Record< string, string >;
}

/** What `getSetting( 'woodev-shipping-locality_data' )` answers. */
export interface LocalityData {
	enabled: boolean;
	location?: LocationConfig;
}

/** A WooCommerce Blocks address, as `wc/store/cart` holds it (snake_case keys). */
export interface WcAddress {
	first_name?: string;
	last_name?: string;
	company?: string;
	address_1?: string;
	address_2?: string;
	city: string;
	state: string;
	postcode?: string;
	country: string;
	phone?: string;
	[ extra: string ]: string | undefined;
}

/** The locality the shopper chose and what it wrote into the native address. */
export interface Selection {
	key: string;
	/** The record's bare city name. */
	city: string;
	/** The record's own type word for that city («г», «рп», «аул»), `''` when it published none. */
	cityType: string;
	country: string;
	/**
	 * The WooCommerce state code the record's region stands for — written by a pick, derived from
	 * the same state list for a selection restored from the server — or `null` when there is none
	 * to vouch for (region field removed, no state list, no single matching option): the state is
	 * then not watched.
	 */
	state: string | null;
}

/**
 * What `/location/select` answered, reduced to what the chooser acts on.
 *
 * The failures are split by what the SERVER now holds: `not-persisted`, `cancelled` and `refused`
 * are answers — nothing was written; `unreachable` is the absence of one (network error, timeout,
 * a 5xx) — the write may have landed.
 */
export type SelectResult =
	| { ok: true; persisted: true }
	| { ok: false; reason: 'not-persisted' | 'cancelled' | 'refused' | 'unreachable'; message?: string };
