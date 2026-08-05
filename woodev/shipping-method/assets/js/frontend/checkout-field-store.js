/**
 * Woodev Checkout Field Store
 *
 * Framework-agnostic vanilla JS store (no jQuery, no DOM, no WC-blocks import).
 * Holds checkout field state, evaluates the condition grammar (a faithful mirror
 * of PHP Checkout_Condition), and exposes cascade/takeover helpers.
 *
 * The condition grammar mirrors woodev/shipping-method/checkout/class-checkout-condition.php
 * exactly — see §11 of docs-internal/specs/2026-07-06-checkout-field-layer-design.md.
 *
 * UMD-ish dual export:
 *   - Browser global: window.WoodevCheckoutFieldStore = { createStore }
 *   - CommonJS:       module.exports = { createStore }  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	// -------------------------------------------------------------------------
	// Condition evaluator — mirrors PHP Checkout_Condition byte-for-byte
	// -------------------------------------------------------------------------

	/**
	 * Coerce any value to a comparison string.
	 *
	 * Mirrors PHP Checkout_Condition::scalar():
	 *   bool true  → '1'
	 *   bool false → ''
	 *   number|string → String(v)
	 *   objects/arrays/null/undefined → ''
	 *
	 * @param {*} v
	 * @returns {string}
	 */
	function scalar( v ) {
		if ( typeof v === 'boolean' ) {
			return v ? '1' : '';
		}
		if ( typeof v === 'number' || typeof v === 'string' ) {
			return String( v );
		}
		return '';
	}

	/**
	 * Evaluate a single { state, operator, value } condition triplet.
	 *
	 * Mirrors PHP Checkout_Condition::evaluate().
	 * Unknown operators → false (fail-open gate).
	 *
	 * @param {Object}              c     Single condition: state/operator/value keys.
	 * @param {Object.<string, *>}  state Flat checkout state map.
	 * @returns {boolean}
	 */
	function evaluate( c, state ) {
		var actual   = scalar( state[ String( c.state   || '' ) ] !== undefined ? state[ String( c.state || '' ) ] : '' );
		var operator = String( c.operator || '' );
		var value    = c.value !== undefined ? c.value : '';

		switch ( operator ) {
			case '=':
				return actual === scalar( value );
			case '!=':
				return actual !== scalar( value );
			case 'in':
				return Array.isArray( value ) && value.map( scalar ).indexOf( actual ) !== -1;
			case 'not_in':
				return Array.isArray( value ) && value.map( scalar ).indexOf( actual ) === -1;
			default:
				return false; // fail-open gate: unknown operator → never required
		}
	}

	/**
	 * Determine whether a field is required given its `required` descriptor
	 * and the current checkout state.
	 *
	 * Mirrors PHP Checkout_Condition::is_required() exactly:
	 *   - plain bool                      → passthrough
	 *   - non-object / empty object       → false  (note: [] is treated as object check)
	 *   - has `conditions` array:
	 *       empty conditions []           → false  (avoid every([])==true)
	 *       relation AND/OR               → fold results
	 *   - otherwise                       → evaluate single condition
	 *
	 * @param {boolean|Object} required Condition spec or plain bool.
	 * @param {Object}         state    Flat key→value checkout state map.
	 * @returns {boolean}
	 */
	function isRequired( required, state ) {
		// bool passthrough
		if ( typeof required === 'boolean' ) {
			return required;
		}

		// non-object or null → false
		if ( ! required || typeof required !== 'object' ) {
			return false;
		}

		// empty plain object {} → false  (mirrors PHP [] === $required check)
		if ( Object.keys( required ).length === 0 ) {
			return false;
		}

		// multi-condition branch
		if ( Array.isArray( required.conditions ) ) {
			// empty conditions array → false  (parity: avoids every([])==true)
			if ( required.conditions.length === 0 ) {
				return false;
			}

			var relation = String( required.relation || 'AND' ).toUpperCase();
			var results  = required.conditions.map( function( c ) {
				return ( c !== null && typeof c === 'object' && ! Array.isArray( c ) ) && evaluate( c, state );
			} );

			return relation === 'OR'
				? results.indexOf( true ) !== -1
				: results.indexOf( false ) === -1;
		}

		// single-condition
		return evaluate( required, state );
	}

	// -------------------------------------------------------------------------
	// Store factory
	// -------------------------------------------------------------------------

	/**
	 * Registry of every store instance createStore() has ever built, in creation
	 * order — additive to the factory, never a replacement for it. Exists so a
	 * consumer that only has a field id (the pickup mount, Task 12) can reach the
	 * SAME store instance §8's own adapter reads/writes, instead of building a
	 * second instance from the same config global whose state would silently
	 * diverge from the one the A2 gate actually recomputes against. See
	 * getStoreForField() below.
	 *
	 * @type {Object[]}
	 */
	var _registry = [];

	/**
	 * Finds the registered store that owns the given field id.
	 *
	 * Deliberately keyed by FIELD OWNERSHIP, not by plugin id: several plugin ids
	 * can sanitize down to the same JS global name (see
	 * config_object_suffix()/PREFIX collisions in checkout-field-classic.js), so a
	 * plugin-id lookup can silently resolve to the wrong store. A field id is
	 * unique to the store whose config declared it, which is exactly what
	 * entryForField() in checkout-field-classic.js already does locally — this
	 * hoists the same rule into the module so any consumer can use it without
	 * reaching into that adapter's private `stores` array.
	 *
	 * Searches most-recently-created store first. This is a TIE-BREAK, not a
	 * claim that the newest registration is the "correct" one: two DIFFERENT
	 * plugins' configs both declaring the same field id is a misconfiguration,
	 * not a supported scenario, and in production "creation order" only ever
	 * reduces to whatever order `checkout-field-classic.js` iterates
	 * `window` keys in — itself dependent on `wp_localize_script()` print
	 * order, which is arbitrary and not something a plugin author controls.
	 * The tie-break exists so behaviour is at least DETERMINISTIC (and, in a
	 * test re-registering the same field id against a fresh store, picks the
	 * instance actually wired to the current page/session) rather than to
	 * imply a real collision resolves sensibly.
	 *
	 * @param {string} fieldId
	 * @returns {Object|null} the owning store, or null when no registered store
	 *                        declares this field.
	 */
	function getStoreForField( fieldId ) {
		for ( var i = _registry.length - 1; i >= 0; i-- ) {
			if ( _registry[ i ].getField( fieldId ) ) {
				return _registry[ i ];
			}
		}

		return null;
	}

	/**
	 * Create a checkout field store bound to the given config.
	 *
	 * Every instance this factory builds is registered (see _registry above) the
	 * moment it is created — a caller never opts out, and never needs to; it is
	 * additive bookkeeping, not a change to what createStore() returns.
	 *
	 * @param {Object} config
	 * @param {Object.<string, Object>} config.fields   Field descriptors keyed by field id.
	 * @param {Object.<string, Object>} [config.takeover] Per-field per-country takeover map.
	 * @param {string}                  [config.endpoint] AJAX endpoint URL.
	 * @param {string}                  [config.nonce]    WordPress nonce.
	 * @returns {Object} Store API.
	 */
	function createStore( config ) {
		var _config  = config  || {};
		var _fields  = _config.fields   || {};
		var _takeover = _config.takeover || {};

		/** @type {Object.<string, *>} flat key→value state map */
		var _state = {};

		// -----------------------------------------------------------------
		// State mutators
		// -----------------------------------------------------------------

		/**
		 * Set a field value in the state map.
		 *
		 * @param {string} id
		 * @param {*}      val
		 * @returns {void}
		 */
		function setValue( id, val ) {
			_state[ id ] = val;
		}

		/**
		 * Get a field value from the state map.
		 *
		 * @param {string} id
		 * @returns {*}
		 */
		function getValue( id ) {
			return _state[ id ];
		}

		/**
		 * Set the currently chosen shipping method.
		 *
		 * Stored under the `chosen_shipping_method` state key — the same key
		 * used by condition specs on `pvz`-type fields.
		 *
		 * @param {string} method
		 * @returns {void}
		 */
		function setChosenMethod( method ) {
			// WooCommerce posts the chosen method as `method_id:instance_id`, but a
			// condition-spec targets the bare `method_id` (instance ids are dynamic). Strip
			// the instance so `{ operator: 'in', value: [ 'method_id' ] }` matches. Mirrors the
			// server (Checkout_Handler::chosen_shipping_method()).
			_state.chosen_shipping_method = String( method == null ? '' : method ).split( ':' )[ 0 ];
		}

		/**
		 * Set the billing/shipping country.
		 *
		 * @param {string} country ISO-2 country code.
		 * @returns {void}
		 */
		function setCountry( country ) {
			_state.country = country;
		}

		// -----------------------------------------------------------------
		// Condition helpers
		// -----------------------------------------------------------------

		/**
		 * Evaluate whether the field with the given id is currently required.
		 *
		 * Delegates to the PHP-parity isRequired() function with a snapshot of
		 * the current state.
		 *
		 * @param {string} fieldId
		 * @returns {boolean}
		 */
		function evaluateRequired( fieldId ) {
			var field = _fields[ fieldId ];
			if ( ! field ) {
				return false;
			}
			return isRequired( field.required !== undefined ? field.required : false, _state );
		}

		/**
		 * Return array of field ids whose `depends_on` equals parentId.
		 *
		 * @param {string} parentId
		 * @returns {string[]}
		 */
		function childrenOf( parentId ) {
			return Object.keys( _fields ).filter( function( id ) {
				return _fields[ id ].depends_on === parentId;
			} );
		}

		/**
		 * Whether this plugin takes over the given field for the given country.
		 *
		 * @param {string} fieldId
		 * @param {string} country ISO-2 country code.
		 * @returns {boolean}
		 */
		function takeoverFor( fieldId, country ) {
			return ( _takeover[ fieldId ] && _takeover[ fieldId ][ country ] ) === true;
		}

		// -----------------------------------------------------------------
		// Read-only accessors
		// -----------------------------------------------------------------

		/**
		 * Get a single field descriptor.
		 *
		 * @param {string} id
		 * @returns {Object|undefined}
		 */
		function getField( id ) {
			return _fields[ id ];
		}

		/**
		 * Get all field descriptors.
		 *
		 * @returns {Object.<string, Object>}
		 */
		function allFields() {
			return _fields;
		}

		/**
		 * Get the configured AJAX endpoint URL.
		 *
		 * @returns {string|undefined}
		 */
		function getEndpoint() {
			return _config.endpoint;
		}

		/**
		 * Get the configured WordPress nonce.
		 *
		 * @returns {string|undefined}
		 */
		function getNonce() {
			return _config.nonce;
		}

		var store = {
			setValue:        setValue,
			getValue:        getValue,
			setChosenMethod: setChosenMethod,
			setCountry:      setCountry,
			evaluateRequired: evaluateRequired,
			childrenOf:      childrenOf,
			takeoverFor:     takeoverFor,
			getField:        getField,
			allFields:       allFields,
			getEndpoint:     getEndpoint,
			getNonce:        getNonce,
		};

		_registry.push( store );

		return store;
	}

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	var api = { createStore: createStore, getStoreForField: getStoreForField };

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevCheckoutFieldStore = api;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}

}() );
