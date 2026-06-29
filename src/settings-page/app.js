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
import TabsNav from '../components/tabs-nav';
import SectionView from './section-view';

export default function App() {
	const [ tabs, setTabs ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ edits, setEdits ] = useState( {} ); // { providerId: { settingId: value } }
	const [ saving, setSaving ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ saveError, setSaveError ] = useState( '' );

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

				// Also surface a native WP (snackbar) notice, not just the inline one.
				dispatch( noticesStore ).createSuccessNotice(
					__( 'Настройки сохранены.', 'woodev-plugin-framework' ),
					{ type: 'snackbar' }
				);

				// Best-effort re-fetch so the UI reflects persisted (coerced) values.
				// A refresh failure must NOT be reported as a save failure. Clear the
				// tab's local edits only once the refresh lands.
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
				const message = ( err && err.message ) ||
					__( 'Не удалось сохранить настройки.', 'woodev-plugin-framework' );
				setSaveError( message );
				dispatch( noticesStore ).createErrorNotice( message, { type: 'snackbar' } );
			} );
	};

	const renderSection = ( tab, sectionId ) => {
		const section = tab.sections.find( ( s ) => s.id === sectionId ) || tab.sections[ 0 ];
		const values = edits[ tab.id ] || {};
		const hasChanges = Object.keys( values ).length > 0;

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
						providerId={ tab.id }
						section={ section }
						values={ values }
						onFieldChange={ ( settingId, value ) =>
							onFieldChange( tab.id, settingId, value )
						}
					/>
					<div className="woodev-settings__actions">
						<Button
							variant="primary"
							isBusy={ saving === tab.id }
							disabled={ saving === tab.id || ! hasChanges }
							onClick={ () => onSave( tab.id ) }
						>
							{ __( 'Сохранить', 'woodev-plugin-framework' ) }
						</Button>
					</div>
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
