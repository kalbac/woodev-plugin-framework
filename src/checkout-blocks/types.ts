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
	current: ChainEntry | null;
	chain: Record< string, ChainEntry > | ChainEntry[];
	implicit: boolean;
	savedCityUnresolved: string | null;
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

/** The locality the shopper chose and the city text it wrote into the native address. */
export interface Selection {
	key: string;
	city: string;
	country: string;
}

/** What `/location/select` answered, reduced to what the chooser acts on. */
export type SelectResult =
	| { ok: true; persisted: true }
	| { ok: false; reason: 'not-persisted' | 'cancelled' | 'failed'; message?: string };
