/**
 * Settings page root component — provider tabs over the aggregated schema with
 * per-tab save.
 *
 * Classic JSX runtime: createElement / Fragment used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner, TabPanel } from '@wordpress/components';
import { fetchSchema, saveTab } from './rest';
import SectionView from './section-view';

export default function App() {
	const [ tabs, setTabs ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ edits, setEdits ] = useState( {} ); // { providerId: { settingId: value } }
	const [ saving, setSaving ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ saveError, setSaveError ] = useState( '' );

	useEffect( () => {
		fetchSchema()
			.then( ( res ) => setTabs( ( res && res.tabs ) || [] ) )
			.catch( () =>
				setLoadError( __( 'Не удалось загрузить настройки.', 'woodev-plugin-framework' ) )
			);
	}, [] );

	if ( loadError ) {
		return createElement(
			Notice,
			{ status: 'error', isDismissible: false },
			loadError
		);
	}

	if ( null === tabs ) {
		return createElement(
			'div',
			{ className: 'woodev-settings__loading' },
			createElement( Spinner ),
			createElement( 'span', null, __( 'Загрузка…', 'woodev-plugin-framework' ) )
		);
	}

	if ( 0 === tabs.length ) {
		return createElement(
			Notice,
			{ status: 'info', isDismissible: false },
			__( 'Нет доступных настроек.', 'woodev-plugin-framework' )
		);
	}

	const onFieldChange = ( providerId, settingId, value ) => {
		setSaved( '' );
		setEdits( ( prev ) => ( {
			...prev,
			[ providerId ]: { ...( prev[ providerId ] || {} ), [ settingId ]: value },
		} ) );
	};

	const onSave = ( providerId ) => {
		setSaving( providerId );
		setSaveError( '' );
		setSaved( '' );
		saveTab( providerId, edits[ providerId ] || {} )
			.then( () => {
				setSaving( '' );
				setSaved( providerId );

				// Best-effort re-fetch so the UI reflects the persisted (possibly
				// coerced/sanitized) values. A refresh failure must NOT be reported
				// as a save failure — the save already succeeded. Only clear the
				// tab's local edits once the refresh lands, so a failed refresh
				// still shows the user the values they entered.
				fetchSchema()
					.then( ( res ) => {
						if ( res && res.tabs ) {
							setTabs( res.tabs );
						}
						setEdits( ( prev ) => {
							const next = { ...prev };
							delete next[ providerId ];
							return next;
						} );
					} )
					.catch( () => {} );
			} )
			.catch( ( err ) => {
				setSaving( '' );
				setSaveError(
					( err && err.message ) ||
						__( 'Не удалось сохранить настройки.', 'woodev-plugin-framework' )
				);
			} );
	};

	const tabOptions = tabs.map( ( tab ) => ( { name: tab.id, title: tab.label } ) );

	return createElement(
		'div',
		{ className: 'woodev-settings' },
		createElement(
			'div',
			{ className: 'woodev-settings__panel' },
			createElement(
				TabPanel,
				{
					className: 'woodev-settings__tabs',
					tabs: tabOptions,
					// Clear cross-tab save notices when switching tabs.
					onSelect: () => {
						setSaveError( '' );
						setSaved( '' );
					},
				},
				( tabOption ) => {
					const tab = tabs.find( ( t ) => t.id === tabOption.name );
					const values = edits[ tab.id ] || {};

					return createElement(
						Fragment,
						null,
						createElement(
							'div',
							{ className: 'woodev-settings__body' },
							saveError &&
								'' === saving &&
								createElement(
									Notice,
									{ status: 'error', onRemove: () => setSaveError( '' ) },
									saveError
								),
							saved === tab.id &&
								createElement(
									Notice,
									{
										status: 'success',
										isDismissible: true,
										onRemove: () => setSaved( '' ),
									},
									__( 'Настройки сохранены.', 'woodev-plugin-framework' )
								),
							createElement( SectionView, {
								tab,
								values,
								onFieldChange: ( settingId, value ) =>
									onFieldChange( tab.id, settingId, value ),
							} )
						),
						createElement(
							'div',
							{ className: 'woodev-settings__footer' },
							createElement(
								Button,
								{
									variant: 'primary',
									isBusy: saving === tab.id,
									disabled: saving === tab.id,
									onClick: () => onSave( tab.id ),
								},
								__( 'Сохранить', 'woodev-plugin-framework' )
							)
						)
					);
				}
			)
		)
	);
}
