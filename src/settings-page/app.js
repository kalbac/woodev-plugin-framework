/**
 * Settings page root — provider folder-tabs over the aggregated schema, with
 * per-provider section sub-tabs and per-provider save.
 *
 * Rebuilt on the UI-kit (TabsNav + Card + FieldRow). Authored in JSX
 * (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { Button, Notice, Spinner, Card, CardBody, SnackbarList } from '@wordpress/components';
import { fetchSchema, saveTab } from './rest';
import { validateFields, isFieldVisible } from '../components/validate';
import { getProviderMismatchError } from '../components/location-picker-field';
import { ACTIVE_PROVIDER_SETTING_ID } from '../components/control-field';
import TabsNav from '../components/tabs-nav';
import SectionView from './section-view';

/**
 * Builds the REST save payload from staged edits, dropping any field whose current
 * schema says `disabled: true` — a disabled field is rendered read-only (D11) and
 * must never leave the browser on save, even if it is still staged as an edit from
 * before it became disabled (e.g. a store setting changed underneath it).
 *
 * @since 2.0.2
 * @param {Object} fields the tab's merged field schema map (settingId => schema slice).
 * @param {Object} edits  staged edits for this tab (settingId => value).
 * @return {Object} `edits` with any disabled-field keys removed.
 */
export function buildSavePayload( fields, edits ) {
	const payload = {};
	Object.keys( edits ).forEach( ( id ) => {
		if ( fields[ id ] && fields[ id ].disabled ) {
			return;
		}
		payload[ id ] = edits[ id ];
	} );
	return payload;
}

/**
 * Fields eligible for client-side validation: visible AND not disabled. A disabled
 * field is excluded from the save payload (see `buildSavePayload`), so a stale or
 * now-invalid value stored under it (e.g. `country_field: 'hide'` after the store
 * started shipping to several countries) must never block Save for the rest of the
 * tab (D11).
 *
 * @since 2.0.2
 * @param {Object} fields the tab's merged field schema map (settingId => schema slice).
 * @param {Object} values current effective values (settingId => value).
 * @return {Object} the subset of `fields` eligible for validation.
 */
export function validatableFields( fields, values ) {
	const out = {};
	Object.keys( fields ).forEach( ( id ) => {
		if ( isFieldVisible( fields[ id ], values ) && ! fields[ id ].disabled ) {
			out[ id ] = fields[ id ];
		}
	} );
	return out;
}

/**
 * Gets client-side provider-mismatch errors that are safe to use as a Save
 * disablement. A mismatch is only proven locally when the fixed record belongs
 * to the raw provider value that loaded with this form; otherwise the server's
 * provider fallback/filter resolution remains the authority and Save fails open.
 *
 * @since 2.0.2
 * @param {Object} fields current tab field schemas.
 * @param {Object} values current effective form values.
 * @return {Object} location-picker setting ids mapped to mismatch errors.
 */
export function getBlockingProviderMismatchErrors( fields, values ) {
	const errors = {};
	const visibleFields = validatableFields( fields, values );
	const persistedProviderId = ( fields[ ACTIVE_PROVIDER_SETTING_ID ] && fields[ ACTIVE_PROVIDER_SETTING_ID ].value ) || '';
	const providerId = values[ ACTIVE_PROVIDER_SETTING_ID ] || '';

	Object.keys( visibleFields ).forEach( ( id ) => {
		if ( 'location-picker' !== visibleFields[ id ].controlType ) {
			return;
		}
		const mismatch = getProviderMismatchError( values[ id ], providerId, persistedProviderId );
		if ( mismatch ) {
			errors[ id ] = mismatch;
		}
	} );

	return errors;
}

/**
 * Whether a proven fixed-locality/provider mismatch should disable Save now.
 *
 * @since 2.0.2
 * @param {Object} fields current tab field schemas.
 * @param {Object} values current effective form values.
 * @return {boolean} whether Save must be disabled before a REST request.
 */
export function hasBlockingProviderMismatch( fields, values ) {
	return Object.keys( getBlockingProviderMismatchErrors( fields, values ) ).length > 0;
}

export default function App() {
	const [ tabs, setTabs ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ edits, setEdits ] = useState( {} ); // { providerId: { settingId: value } }
	const [ saving, setSaving ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ saveError, setSaveError ] = useState( '' );
	const [ showErrors, setShowErrors ] = useState( {} ); // { providerId: bool }
	const [ fieldErrors, setFieldErrors ] = useState( {} ); // { providerId: { settingId: message } }
	const [ errorRevealGen, setErrorRevealGen ] = useState( 0 );

	// Native WP snackbar notices (created on save via the notices store).
	const snackbars = useSelect(
		( select ) => select( noticesStore ).getNotices().filter( ( n ) => 'snackbar' === n.type ),
		[]
	);

	useEffect( () => {
		fetchSchema()
			.then( ( res ) => setTabs( ( res && res.tabs ) || [] ) )
			.catch( () =>
				setLoadError( __( 'Не удалось загрузить настройки.', 'woodev-plugin-framework' ) )
			);
	}, [] );

	useEffect( () => {
		if ( ! errorRevealGen ) {
			return;
		}
		const el = document.querySelector( '.woodev-settings .woodev-field--error' );
		if ( el ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			const control = el.querySelector( 'input, textarea, button' );
			if ( control ) {
				control.focus( { preventScroll: true } );
			}
		}
	}, [ errorRevealGen ] );

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	}

	if ( null === tabs ) {
		return (
			<div className="woodev-settings__loading">
				<Spinner />
				<span>{ __( 'Загрузка…', 'woodev-plugin-framework' ) }</span>
			</div>
		);
	}

	if ( 0 === tabs.length ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __( 'Нет доступных настроек.', 'woodev-plugin-framework' ) }
			</Notice>
		);
	}

	const onFieldChange = ( providerId, settingId, value ) => {
		setSaved( '' );
		setFieldErrors( ( prev ) => {
			const tabErrs = { ...( prev[ providerId ] || {} ) };
			delete tabErrs[ settingId ];
			return { ...prev, [ providerId ]: tabErrs };
		} );
		setEdits( ( prev ) => ( {
			...prev,
			[ providerId ]: { ...( prev[ providerId ] || {} ), [ settingId ]: value },
		} ) );
	};

	// Drops a single staged edit, restoring the field's untouched (persisted)
	// value — used to cancel a pending sensitive-secret wipe without touching the
	// rest of the tab's edits.
	const onFieldRevert = ( providerId, settingId ) => {
		setSaved( '' );
		setFieldErrors( ( prev ) => {
			const tabErrs = { ...( prev[ providerId ] || {} ) };
			delete tabErrs[ settingId ];
			return { ...prev, [ providerId ]: tabErrs };
		} );
		setEdits( ( prev ) => {
			const tabEdits = { ...( prev[ providerId ] || {} ) };
			delete tabEdits[ settingId ];
			return { ...prev, [ providerId ]: tabEdits };
		} );
	};

	// #515: `edits[providerId]` stays a flat, tab-wide map (see the
	// react-missing-key-state-bleed-across-tabs gotcha), but a save must only
	// touch the CURRENTLY OPEN section's own settings — a sibling section's
	// staged-but-unsaved edits must neither block/pass this section's
	// validation nor reach the REST payload, and must survive this save.
	const onSave = ( providerId, tab, section ) => {
		const providerEdits = edits[ providerId ] || {};
		const sectionFields = section.fields || {};
		const sectionFieldIds = Object.keys( sectionFields );

		const sectionEdits = {};
		sectionFieldIds.forEach( ( id ) => {
			if ( Object.prototype.hasOwnProperty.call( providerEdits, id ) ) {
				sectionEdits[ id ] = providerEdits[ id ];
			}
		} );

		let payload = sectionEdits;

		// SP-2: a connection section's credential fields skip client-side field
		// validation and the provider-mismatch check entirely (unchanged from
		// before this fix) — the difference now is only that the SAVE ITSELF is
		// scoped to this one section rather than the whole tab.
		if ( ! section.is_connection ) {
			// Tab-wide effective values (excluding connection sections, same as
			// before) so a cross-section `show_if` still resolves correctly even
			// though only this section's fields are being validated/saved.
			const allFields = {};
			( tab.sections || [] ).forEach( ( s ) => {
				if ( ! s.is_connection ) {
					Object.assign( allFields, s.fields || {} );
				}
			} );
			const merged = {};
			Object.keys( allFields ).forEach( ( id ) => {
				merged[ id ] = providerEdits[ id ] ?? allFields[ id ].value;
			} );

			const visibleFields = validatableFields( sectionFields, merged );

			const clientErrors = validateFields( visibleFields, merged, sectionEdits );

			// Issue #406: a FIXED default-locality record from a different provider
			// than the one THIS save visibly switched away from must block Save. The
			// exact server resolver can apply a fallback or public filter, so unknown
			// raw-id states deliberately fall through to its authoritative check.
			// `visibleFields` already excludes a `default_locality_record` hidden by
			// `show_if` (policy switched to `off` in this same save), so the rule
			// lifts itself the same way it does server-side.
			Object.assign( clientErrors, getBlockingProviderMismatchErrors( sectionFields, merged ) );

			if ( Object.keys( clientErrors ).length > 0 ) {
				setShowErrors( ( p ) => ( { ...p, [ providerId ]: true } ) );
				setFieldErrors( ( p ) => ( { ...p, [ providerId ]: {} } ) ); // clear stale server errors before revealing fresh client errors
				setErrorRevealGen( ( g ) => g + 1 );
				dispatch( noticesStore ).createErrorNotice(
					__( 'Проверьте правильность заполнения полей.', 'woodev-plugin-framework' ),
					{ type: 'snackbar', id: 'woodev-settings-validate' }
				);
				return; // block REST — reveal fresh client errors only
			}

			payload = buildSavePayload( sectionFields, sectionEdits );
		}

		setSaving( providerId );
		setSaveError( '' );
		setSaved( '' );
		setFieldErrors( ( p ) => ( { ...p, [ providerId ]: {} } ) );

		saveTab( providerId, payload )
			.then( () => {
				setSaving( '' );
				setSaved( providerId );
				setShowErrors( ( p ) => ( { ...p, [ providerId ]: false } ) );

				// Also surface a native WP (snackbar) notice, not just the inline one.
				dispatch( noticesStore ).createSuccessNotice(
					__( 'Настройки сохранены.', 'woodev-plugin-framework' ),
					{ type: 'snackbar' }
				);

				// Best-effort re-fetch so the UI reflects persisted (coerced) values.
				// A refresh failure must NOT be reported as a save failure. Clear only
				// THIS SECTION's local edits once the refresh lands — a sibling
				// section's still-pending edits must survive this save (#515).
				fetchSchema()
					.then( ( res ) => {
						if ( res && res.tabs ) {
							setTabs( res.tabs );
						}
						setEdits( ( prev ) => {
							const tabEdits = { ...( prev[ providerId ] || {} ) };
							sectionFieldIds.forEach( ( id ) => delete tabEdits[ id ] );
							return { ...prev, [ providerId ]: tabEdits };
						} );
					} )
					.catch( () => {} );
			} )
			.catch( ( err ) => {
				setSaving( '' );
				const map = err && err.data && err.data.errors ? err.data.errors : null;
				if ( map ) {
					setFieldErrors( ( p ) => ( { ...p, [ providerId ]: map } ) );
					setShowErrors( ( p ) => ( { ...p, [ providerId ]: true } ) );
					setErrorRevealGen( ( g ) => g + 1 );
				}
				const message = ( err && err.message ) ||
					__( 'Не удалось сохранить настройки.', 'woodev-plugin-framework' );
				setSaveError( message );
				dispatch( noticesStore ).createErrorNotice( message, { type: 'snackbar' } );
			} );
	};

	const renderSection = ( tab, sectionId ) => {
		const section = tab.sections.find( ( s ) => s.id === sectionId ) || tab.sections[ 0 ];
		const values = edits[ tab.id ] || {};
		// #515: Save must reflect edits of the OPEN SECTION only — a sibling
		// section's staged edits must not enable (or count towards) this button.
		const hasChanges = Object.keys( section.fields || {} ).some( ( id ) =>
			Object.prototype.hasOwnProperty.call( values, id )
		);

		// Provider-wide effective values so a field can react to a controller in
		// any section of this tab (live reactivity still only within the open section).
		const conditionValues = {};
		( tab.sections || [] ).forEach( ( s ) => {
			Object.keys( s.fields || {} ).forEach( ( id ) => {
				conditionValues[ id ] = values[ id ] ?? s.fields[ id ].value;
			} );
		} );

		const tabFields = {};
		( tab.sections || [] ).forEach( ( s ) => {
			Object.assign( tabFields, s.fields || {} );
		} );
		const hasProviderMismatch = hasBlockingProviderMismatch( tabFields, conditionValues );

		return (
			<Card className="woodev-settings__card">
				<CardBody>
					{ saveError && '' === saving && (
						<Notice status="error" onRemove={ () => setSaveError( '' ) }>
							{ saveError }
						</Notice>
					) }
					{ saved === tab.id && (
						<Notice
							status="success"
							isDismissible={ true }
							onRemove={ () => setSaved( '' ) }
						>
							{ __( 'Настройки сохранены.', 'woodev-plugin-framework' ) }
						</Notice>
					) }
					<SectionView
						key={ `${ tab.id }:${ section.id }` }
						providerId={ tab.id }
						section={ section }
						tabFields={ tabFields }
						values={ values }
						conditionValues={ conditionValues }
						onFieldChange={ ( settingId, value ) =>
							onFieldChange( tab.id, settingId, value )
						}
						onFieldRevert={ ( settingId ) =>
							onFieldRevert( tab.id, settingId )
						}
						showErrors={ !! showErrors[ tab.id ] }
						serverErrors={ fieldErrors[ tab.id ] || {} }
					/>
					{ ! section.is_tools && (
						<div className="woodev-settings__actions">
							<Button
								variant="primary"
								isBusy={ saving === tab.id }
								disabled={ saving === tab.id || ! hasChanges || hasProviderMismatch }
								onClick={ () => onSave( tab.id, tab, section ) }
							>
								{ __( 'Сохранить', 'woodev-plugin-framework' ) }
							</Button>
						</div>
					) }
				</CardBody>
			</Card>
		);
	};

	return (
		<div className="woodev-settings">
			<TabsNav
				tabs={ tabs }
				renderSection={ renderSection }
				onTabChange={ () => {
					setSaveError( '' );
					setSaved( '' );
				} }
			/>
			<SnackbarList
				className="woodev-settings__snackbars"
				notices={ snackbars }
				onRemove={ ( id ) => dispatch( noticesStore ).removeNotice( id ) }
			/>
		</div>
	);
}
