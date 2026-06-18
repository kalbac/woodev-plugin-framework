/**
 * License Card component.
 *
 * Renders one plugin's license controls inside a @wordpress/components Card.
 * State is replaced wholesale by each REST response (the returned get_state()
 * array). Request errors surface the WP_Error message in an error Notice —
 * no silent catch.
 *
 * The card's presentation (status badge, accent bar, key-field mode, action
 * set) is derived by the PURE getCardView() helper (./card-state) from the
 * approved 7-group state machine; this component stays declarative.
 *
 * __experimentalConfirmDialog availability:
 *   - Exported by @wordpress/components from WP ≥ 6.2 (graduated from
 *     internal, still under the experimental prefix). This framework targets
 *     WP 6.3+, so __experimentalConfirmDialog exists in the wp.components
 *     global and the import is safe. No Modal fallback is implemented.
 *
 * Eye button approach:
 *   - @wordpress/icons is bundled (not externalized per ADR-007 §4); importing
 *     it would add ~50 kB to the bundle. We avoid that by using dashicons CSS
 *     class names inside a <span> element rendered as Button children.
 *   - The eye toggle shows/hides the full license key client-side only; no
 *     extra REST request is made.
 *
 * RawHTML safety:
 *   - The `message` field rendered via RawHTML is sanitized on the PHP layer
 *     with wp_kses_post() inside Woodev_Plugins_License::get_state() before
 *     it reaches the bootstrap payload or any REST response. RawHTML is
 *     therefore safe to use here.
 *
 * Classic JSX runtime: { createElement, Fragment } MUST be imported here.
 *
 * @package woodev-plugin-framework
 */

// eslint-disable-next-line no-unused-vars -- classic JSX runtime pragma.
import { createElement, Fragment, RawHTML } from '@wordpress/element';
import { useState, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	Flex,
	FlexItem,
	TextControl,
	Button,
	Notice,
	ToggleControl,
	Tooltip,
	Spinner,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { getCardView } from './card-state';

/**
 * Masks a license key, keeping the first 4 and last 4 characters visible.
 *
 * @param {string} key The full license key.
 * @return {string} Masked key string.
 */
function maskKey( key ) {
	if ( ! key || key.length <= 8 ) {
		return key;
	}
	return key.slice( 0, 4 ) + '•'.repeat( Math.max( 4, key.length - 8 ) ) + key.slice( -4 );
}

/**
 * LicenseCard — renders one plugin's license controls.
 *
 * @param {Object} props              Component props.
 * @param {Object} props.initialState The get_state() object for this plugin.
 *
 * @return {WPElement} Rendered card.
 */
export default function LicenseCard( { initialState } ) {
	const [ state, setState ] = useState( initialState );
	const [ keyInput, setKeyInput ] = useState( initialState.license_key || '' );
	const [ revealKey, setRevealKey ] = useState( false );
	// «Изменить ключ» — client-only: replace a saved key without deactivating.
	const [ editingKey, setEditingKey ] = useState( false );
	// Single shared busy flag: any in-flight mutation disables ALL action
	// controls until the request settles (prevents concurrent mutations and
	// stale-state races from overlapping verify/deactivate/beta requests).
	const [ busy, setBusy ] = useState( false );
	// Synchronous mutex: guards against two events fired before the disabled-
	// state re-render both starting a request. React state updates are
	// batched/async, so `busy` may still be false when a second event fires;
	// a ref update is synchronous and is therefore the correct primitive here.
	const mutationLock = useRef( false );
	// Per-action isBusy flags for individual spinner/button presentation.
	const [ verifying, setVerifying ] = useState( false );
	const [ deactivating, setDeactivating ] = useState( false );
	const [ togglingBeta, setTogglingBeta ] = useState( false );
	const [ confirmOpen, setConfirmOpen ] = useState( false );
	const [ error, setError ] = useState( null );

	const {
		plugin_id: pluginId,
		plugin_name: pluginName,
		license_key: licenseKey,
		message,
		message_variant: messageVariant,
		is_need_license: isNeedLicense,
		beta_enabled: betaEnabled,
	} = state;

	// ------------------------------------------------------------------ //
	// Action handlers
	// ------------------------------------------------------------------ //

	async function handleVerify() {
		if ( mutationLock.current ) {
			return;
		}
		mutationLock.current = true;
		try {
			setBusy( true );
			setVerifying( true );
			setError( null );
			const response = await apiFetch( {
				path: `/woodev/v1/licenses/${ pluginId }/verify`,
				method: 'POST',
				data: { license_key: keyInput },
			} );
			setState( response );
			setKeyInput( response.license_key || '' );
			setEditingKey( false );
		} catch ( err ) {
			setError(
				err && err.message
					? err.message
					: __( 'Не удалось выполнить запрос к серверу лицензий.', 'woodev-plugin-framework' )
			);
		} finally {
			mutationLock.current = false;
			setVerifying( false );
			setBusy( false );
		}
	}

	async function handleDeactivate() {
		if ( mutationLock.current ) {
			return;
		}
		mutationLock.current = true;
		try {
			setConfirmOpen( false );
			setBusy( true );
			setDeactivating( true );
			setError( null );
			const response = await apiFetch( {
				path: `/woodev/v1/licenses/${ pluginId }/deactivate`,
				method: 'POST',
			} );
			setState( response );
			setKeyInput( response.license_key || '' );
			setEditingKey( false );
		} catch ( err ) {
			setError(
				err && err.message
					? err.message
					: __( 'Не удалось деактивировать лицензию. Попробуйте ещё раз.', 'woodev-plugin-framework' )
			);
		} finally {
			mutationLock.current = false;
			setDeactivating( false );
			setBusy( false );
		}
	}

	async function handleBetaToggle( enabled ) {
		if ( mutationLock.current ) {
			return;
		}
		mutationLock.current = true;
		try {
			setBusy( true );
			setTogglingBeta( true );
			setError( null );
			const response = await apiFetch( {
				path: `/woodev/v1/licenses/${ pluginId }/beta`,
				method: 'POST',
				data: { enabled },
			} );
			setState( response );
		} catch ( err ) {
			setError(
				err && err.message
					? err.message
					: __( 'Не удалось сохранить настройку бета-версий.', 'woodev-plugin-framework' )
			);
		} finally {
			mutationLock.current = false;
			setTogglingBeta( false );
			setBusy( false );
		}
	}

	// ------------------------------------------------------------------ //
	// Shared error notice — rendered in BOTH the license-free and standard
	// branches so a failed beta toggle is always visible to the user.
	// ------------------------------------------------------------------ //

	const errorNotice = error ? (
		<Notice
			status="error"
			isDismissible
			onRemove={ () => setError( null ) }
		>
			{ error }
		</Notice>
	) : null;

	// ------------------------------------------------------------------ //
	// License-free card (is_need_license === false)
	// ------------------------------------------------------------------ //

	if ( ! isNeedLicense ) {
		return (
			<Card className="woodev-license-card woodev-license-card--info woodev-license-card--free">
				<CardHeader>
					<Flex justify="space-between" align="center">
						<FlexItem>
							<strong>{ pluginName }</strong>
						</FlexItem>
						<FlexItem>
							<span className="woodev-license-badge is-info">
								{ __( 'Лицензия не требуется', 'woodev-plugin-framework' ) }
							</span>
						</FlexItem>
					</Flex>
				</CardHeader>
				<CardBody>
					{ errorNotice }
					<Notice status="info" isDismissible={ false }>
						{ __( 'Лицензия для этого плагина не требуется.', 'woodev-plugin-framework' ) }
					</Notice>
				</CardBody>
				<CardFooter>
					<Flex justify="flex-end" align="center">
						<FlexItem className="woodev-license-beta">
							<Tooltip text={ __( 'Разрешает устанавливать бета-версии плагина', 'woodev-plugin-framework' ) }>
								<span className="woodev-license-beta-toggle">
									<ToggleControl
										label={ __( 'Бета', 'woodev-plugin-framework' ) }
										checked={ betaEnabled }
										disabled={ busy }
										onChange={ handleBetaToggle }
									/>
								</span>
							</Tooltip>
							{ togglingBeta && <Spinner /> }
						</FlexItem>
					</Flex>
				</CardFooter>
			</Card>
		);
	}

	// ------------------------------------------------------------------ //
	// Standard licensed card — driven by the derived view descriptor.
	// ------------------------------------------------------------------ //

	const view = getCardView( state, editingKey );
	const showKeyField = view.group !== 'unknown' || !! licenseKey;

	return (
		<Card className={ `woodev-license-card woodev-license-card--${ view.accent }` }>
			{ confirmOpen && (
				<ConfirmDialog
					onConfirm={ handleDeactivate }
					onCancel={ () => setConfirmOpen( false ) }
				>
					{ __( 'Вы уверены, что хотите деактивировать лицензионный ключ?', 'woodev-plugin-framework' ) }
				</ConfirmDialog>
			) }

			<CardHeader>
				<Flex justify="space-between" align="center">
					<FlexItem>
						<strong>{ pluginName }</strong>
					</FlexItem>
					<FlexItem>
						<span className={ `woodev-license-badge is-${ view.badge.variant }` }>
							{ view.badge.label }
						</span>
					</FlexItem>
				</Flex>
			</CardHeader>

			<CardBody>
				{ /* License status message — kses-sanitized on the PHP layer, so RawHTML is safe. */ }
				{ message && (
					<Notice status={ messageVariant || 'info' } isDismissible={ false }>
						<RawHTML>{ message }</RawHTML>
					</Notice>
				) }

				{ errorNotice }

				{ showKeyField && (
					<div className="woodev-license-key-group">
						<TextControl
							className="woodev-license-key-input"
							hideLabelFromVision
							label={ __( 'Лицензионный ключ', 'woodev-plugin-framework' ) }
							value={ view.keyEditable
								? keyInput
								: ( revealKey ? licenseKey : maskKey( licenseKey ) ) }
							readOnly={ ! view.keyEditable }
							onChange={ ( value ) => setKeyInput( value ) }
							placeholder={ __( 'Укажите ваш ключ', 'woodev-plugin-framework' ) }
						/>
						<Button
							className="woodev-license-eye-button"
							variant="secondary"
							disabled={ ! view.controlsEnabled }
							onClick={ () => setRevealKey( ( prev ) => ! prev ) }
							label={ revealKey
								? __( 'Скрыть ключ', 'woodev-plugin-framework' )
								: __( 'Показать ключ', 'woodev-plugin-framework' ) }
						>
							<span
								className={ `dashicons ${ revealKey ? 'dashicons-hidden' : 'dashicons-visibility' }` }
								aria-hidden="true"
							/>
						</Button>
						<Button
							className="woodev-license-verify-button"
							variant="secondary"
							isBusy={ verifying }
							disabled={ busy || ! view.controlsEnabled }
							onClick={ handleVerify }
						>
							{ __( 'Проверить', 'woodev-plugin-framework' ) }
						</Button>
					</div>
				) }
			</CardBody>

			<CardFooter>
				<Flex justify="space-between" align="center" wrap>
					<FlexItem>
						<Flex gap={ 2 } align="center" wrap>
							{ view.actions.activate && (
								<Button
									variant="primary"
									isBusy={ verifying }
									disabled={ busy || ! keyInput.trim() }
									onClick={ handleVerify }
								>
									{ verifying
										? __( 'Проверка…', 'woodev-plugin-framework' )
										: __( 'Активировать', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.renew && state.renewal_url && (
								<Button
									variant={ view.actions.renewAccent ? 'primary' : 'secondary' }
									href={ state.renewal_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'Продлить', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.deactivate && (
								<Button
									isDestructive
									disabled={ busy }
									isBusy={ deactivating }
									onClick={ () => setConfirmOpen( true ) }
								>
									{ __( 'Деактивировать', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.changeKey && ! editingKey && (
								<Button
									variant="link"
									disabled={ busy }
									onClick={ () => {
										setEditingKey( true );
										setKeyInput( '' );
										setRevealKey( false );
									} }
								>
									{ __( 'Изменить ключ', 'woodev-plugin-framework' ) }
								</Button>
							) }

							{ view.actions.cancelEdit && (
								<Button
									variant="link"
									disabled={ busy }
									onClick={ () => {
										setEditingKey( false );
										setKeyInput( licenseKey || '' );
									} }
								>
									{ __( 'Отмена', 'woodev-plugin-framework' ) }
								</Button>
							) }
						</Flex>
					</FlexItem>

					<FlexItem className="woodev-license-beta">
						<Tooltip text={ __( 'Разрешает устанавливать бета-версии плагина', 'woodev-plugin-framework' ) }>
							<span className="woodev-license-beta-toggle">
								<ToggleControl
									label={ __( 'Бета', 'woodev-plugin-framework' ) }
									checked={ betaEnabled }
									disabled={ busy }
									onChange={ handleBetaToggle }
								/>
							</span>
						</Tooltip>
						{ togglingBeta && <Spinner /> }
					</FlexItem>
				</Flex>
			</CardFooter>
		</Card>
	);
}
