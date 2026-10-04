/**
 * Shared types of the Checkout Blocks pickup-point button (SP-11 C-2b, #1089).
 *
 * The wire shapes mirror the server — `Pickup_Blocks_Integration::get_script_data()`,
 * `Store_Api_Pickup::cart_data()` and `Pickup_Handler::get_js_config()` — and the host contract
 * mirrors `pickup-session.js`'s own docblock. Read from those, not recalled.
 *
 * @package woodev-plugin-framework
 */

/** `Pickup_Handler::blocks_descriptor()` — how the bundle finds one carrier's pickup field. */
export interface PickupFieldDescriptor {
	/** The Store API transport key — NOT the picker config's `pluginId` (the error reporter's). */
	pluginId: string;
	fieldId: string;
	/** Name of the `window` global the field's picker config is localized under. */
	configKey: string;
}

/** What `getSetting( 'woodev-shipping-pickup_data' )` answers. */
export interface PickupData {
	enabled: boolean;
	/** The Store API extension namespace (`woodev-shipping`). */
	namespace?: string;
	fields?: PickupFieldDescriptor[];
	i18n?: Record< string, string >;
}

/** The classic verdict and advice a confirmation carries (`Pickup_Selection_Service::confirm()`). */
export interface PickupSelectionResult {
	allowed: boolean;
	reason?: string | null;
	close?: boolean | null;
	refresh_checkout?: boolean | null;
	point?: Record< string, unknown > | null;
}

/** One field's confirmed point in the cart's extension data; `null` when nothing is confirmed. */
export interface PickupSnapshot {
	plugin_id: string;
	field_id: string;
	point_id: string;
	locality: string;
	/** The FULL rate id the point was confirmed under. */
	rate_id: string;
	/** The point's short address, raw text (never HTML). */
	summary: string;
	/**
	 * The native shipping-address fields the server moved to the point's own address with this
	 * confirmation (`pickup_replace_address`); an empty list when it moved none.
	 */
	destination?: PickupDestination | unknown[];
	selection?: PickupSelectionResult;
}

/** `Store_Api_Pickup::replace_destination()` — street line and/or postcode, never the city. */
export interface PickupDestination {
	address_1?: string;
	postcode?: string;
}

/** The field that owns the cart's chosen rate (`Store_Api_Pickup::owner()`). */
export interface PickupOwner {
	plugin_id: string;
	field_id: string;
	rate_id: string;
	/** The key the owner's points are addressed by; `''` when no settlement is chosen. */
	locality: string;
}

/** `cart.extensions[ 'woodev-shipping' ]`. */
export interface PickupExtension {
	pickup?: Record< string, Record< string, PickupSnapshot | null > | undefined >;
	owner?: PickupOwner | null;
}

/** The identity of a confirmation, as echoed with the checkout request. */
export type PickupEcho = Pick< PickupSnapshot, 'plugin_id' | 'field_id' | 'point_id' | 'locality' | 'rate_id' >;

/** The slice of `Pickup_Handler::get_js_config()` the block itself reads; the session reads the rest. */
export interface PickupConfig {
	fieldId: string;
	nonce?: string;
	nonceNodeId?: string;
	/** `billingOnly`: the store ships to the billing address, so the two are one address. */
	replaceAddress?: { enabled?: boolean; billingOnly?: boolean };
	i18n?: Record< string, string >;
	themeButtonClass?: string;
	accentColor?: string;
	accentFillColor?: string;
	accentContrastColor?: string;
	[ extra: string ]: unknown;
}

/** A point as the map session hands it over. */
export interface PickupPoint {
	id?: string | number | null;
	[ extra: string ]: unknown;
}

/** A failed round trip, in the shape the session's error mapping reads. */
export interface PickupFailure {
	status: number;
	code: string;
	message: string;
}

/** The surface's answers to `pickup-session.js` — see that file's docblock. */
export interface PickupSessionHost {
	returnFocusTo: HTMLElement | null;
	getSelectedId: () => string;
	getLocality: () => string;
	getLocalityKey: () => string;
	getNonce: () => string;
	getRequestContext: () => Record< string, string > | null;
	confirmSelection: ( point: PickupPoint ) => Promise< PickupSelectionResult >;
	applySelection: ( point: PickupPoint, addressEscaped: boolean ) => void;
	close: () => void;
	checkoutRefresh: null;
}

/** What `WoodevPickupSession.open()` returns. */
export interface PickupSession {
	modal: { isOpen?: () => boolean };
	refresh: () => Promise< unknown >;
	destroy: () => void;
}

/** `window.WoodevPickupSession` (`pickup-session.js`). */
export interface PickupSessionApi {
	open: ( config: PickupConfig, host: PickupSessionHost ) => PickupSession;
}
