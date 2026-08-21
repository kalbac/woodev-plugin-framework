/**
 * Tests for the pure save-payload helpers extracted from settings-page/app.js
 * (D11 — a disabled field is read-only and must never reach the REST save body).
 *
 * Only the pure helpers are covered here — `App` itself pulls in `wp.data` /
 * `@wordpress/notices` / REST fetches, which is out of scope for this task
 * (see `docs-internal/plans/2026-08-18-shipping-settings-v2-plan.md` Task 2).
 *
 * @see src/settings-page/app.js
 */

import {
	buildSavePayload,
	hasBlockingProviderMismatch,
	validatableFields,
} from '../../src/settings-page/app';

describe( 'buildSavePayload', () => {
	test( 'drops a disabled field even when it is staged as an edit', () => {
		const fields = {
			country_field: { disabled: true },
			region_field: {},
		};
		const edits = { country_field: 'hide', region_field: 'show' };

		expect( buildSavePayload( fields, edits ) ).toEqual( {
			region_field: 'show',
		} );
	} );

	test( 'keeps every edit when nothing is disabled', () => {
		const fields = { a: {}, b: {} };
		const edits = { a: 1, b: 2 };

		expect( buildSavePayload( fields, edits ) ).toEqual( { a: 1, b: 2 } );
	} );

	test( 'an edit for an id absent from the schema is passed through unchanged', () => {
		const edits = { ghost: 'x' };

		expect( buildSavePayload( {}, edits ) ).toEqual( { ghost: 'x' } );
	} );
} );

describe( 'validatableFields', () => {
	test( 'excludes a disabled field from client validation — a stale/invalid value under a', () => {
		// now-disabled field (e.g. the store switched to the block checkout) must
		// never block Save for the rest of the tab.
		const fields = {
			country_field: { disabled: true, required: true },
			region_field: { required: true },
		};
		const values = { country_field: '', region_field: 'show' };

		expect( Object.keys( validatableFields( fields, values ) ) ).toEqual( [
			'region_field',
		] );
	} );

	test( 'still excludes fields hidden by show_if, unaffected by disabled', () => {
		const fields = {
			a: { show_if: { setting: 'toggle', operator: '=', value: '1' } },
			b: {},
		};
		const values = { toggle: '', b: 'x' };

		expect( Object.keys( validatableFields( fields, values ) ) ).toEqual( [
			'b',
		] );
	} );
} );

describe( 'hasBlockingProviderMismatch', () => {
	const fixedRecord = ( providerId ) => JSON.stringify( {
		key: `${ providerId }:city-1`,
		label: 'Москва',
		provider_id: providerId,
	} );

	const fields = {
		active_provider: { value: 'cdek' },
		default_locality_record: { controlType: 'location-picker', value: fixedRecord( 'cdek' ) },
	};

	test( 'blocks Save before the round trip for an ordinary in-form provider switch away from a matching fixed record', () => {
		expect(
			hasBlockingProviderMismatch( fields, {
				active_provider: 'dadata',
				default_locality_record: fixedRecord( 'cdek' ),
			} )
		).toBe( true );
	} );

	test( 'fails open when the persisted raw provider already differs from the record provider', () => {
		// A deregistered provider can fall back server-side, and a public filter
		// can substitute another provider instance. The client cannot resolve
		// either path, so this must reach the server rather than dead-ending Save.
		expect(
			hasBlockingProviderMismatch(
				{
					...fields,
					active_provider: { value: 'filtered-provider' },
					default_locality_record: { controlType: 'location-picker', value: fixedRecord( 'dadata' ) },
				},
				{
					active_provider: 'cdek',
					default_locality_record: fixedRecord( 'dadata' ),
				}
			)
		).toBe( false );
	} );

	test( 'unblocks immediately when the provider is restored to the record provider', () => {
		expect(
			hasBlockingProviderMismatch( fields, {
				active_provider: 'cdek',
				default_locality_record: fixedRecord( 'cdek' ),
			} )
		).toBe( false );
	} );
} );
