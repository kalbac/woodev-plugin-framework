/**
 * License Card component.
 *
 * Renders one plugin's license controls inside a @wordpress/components Card.
 * State is replaced wholesale by each REST response (the returned get_state()
 * array). Request errors surface the WP_Error message in an error Notice —
 * no silent catch.
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
	Spinner,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';

/**
 * Masks all characters of a license key except the last 4.
 *
 * @param {string} key The full license key.
 * @return {string} Masked key string.
 */
function maskKey( key ) {
	if ( ! key || key.length <= 4 ) {
		return key;
	}
	return '•'.repeat( key.length - 4 ) + key.slice( -4 );
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
		status_label: statusLabel,
		is_valid: isValid,
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
			<Card className="woodev-license-card woodev-license-card--free">
				<CardHeader>
					<Flex justify="space-between" align="center">
						<FlexItem>
							<strong>{ pluginName }</strong>
						</FlexItem>
					</Flex>
				</CardHeader>
				<CardBody>
					{ errorNotice }
					<Notice
						status="info"
						isDismissible={ false }
					>
						{ __( 'Лицензия для этого плагина не требуется.', 'woodev-plugin-framework' ) }
					</Notice>
				</CardBody>
				<CardFooter>
					<ToggleControl
						label={ __( 'Разрешить установку бета-версий', 'woodev-plugin-framework' ) }
						checked={ state.beta_enabled }
						disabled={ busy }
						onChange={ handleBetaToggle }
					/>
					{ togglingBeta && <Spinner /> }
				</CardFooter>
			</Card>
		);
	}

	// ------------------------------------------------------------------ //
	// Standard licensed card
	// ------------------------------------------------------------------ //

	const statusBadgeClass = `woodev-license-badge is-${ messageVariant || 'info' }`;

	return (
		<Card className="woodev-license-card">
			{ /* Confirm deactivate dialog */ }
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
						<span className={ statusBadgeClass }>
							{ statusLabel || __( 'Неизвестный статус', 'woodev-plugin-framework' ) }
						</span>
					</FlexItem>
				</Flex>
			</CardHeader>

			<CardBody>
				{ /* License status message — content is kses-sanitized by the PHP
				     layer (wp_kses_post in Woodev_Plugins_License::get_state()),
				     so RawHTML is safe here. */ }
				{ message && (
					<Notice
						status={ messageVariant || 'info' }
						isDismissible={ false }
					>
						<RawHTML>{ message }</RawHTML>
					</Notice>
				) }

				{ /* Error notice (from failed API requests) */ }
				{ errorNotice }

				{ /* Key field: masked display when key exists and not revealing */ }
				{ licenseKey && (
					<Flex align="center" className="woodev-license-key-row">
						<FlexItem isBlock>
							{ revealKey
								? <code className="woodev-license-key-value">{ licenseKey }</code>
								: <code className="woodev-license-key-value woodev-license-key-value--masked">{ maskKey( licenseKey ) }</code>
							}
						</FlexItem>
						<FlexItem>
							<Button
								variant="tertiary"
								className="woodev-license-eye-button"
								onClick={ () => setRevealKey( ( prev ) => ! prev ) }
								label={ revealKey
									? __( 'Скрыть ключ', 'woodev-plugin-framework' )
									: __( 'Показать ключ', 'woodev-plugin-framework' )
								}
							>
								<span
									className={ `dashicons ${ revealKey ? 'dashicons-hidden' : 'dashicons-visibility' }` }
									aria-hidden="true"
								/>
							</Button>
						</FlexItem>
					</Flex>
				) }

				{ /* Text input for entering or replacing the key */ }
				<TextControl
					label={ __( 'Лицензионный ключ', 'woodev-plugin-framework' ) }
					value={ keyInput }
					onChange={ ( value ) => setKeyInput( value ) }
					readOnly={ isValid }
					disabled={ isValid }
					placeholder={ __( 'Введите лицензионный ключ', 'woodev-plugin-framework' ) }
					className="woodev-license-key-input"
				/>
			</CardBody>

			<CardFooter>
				<Flex wrap align="center" gap={ 2 }>
					{ /* Verify / activate button — gated on shared busy flag */ }
					<FlexItem>
						<Button
							variant="primary"
							isBusy={ verifying }
							disabled={ busy || ! keyInput.trim() || isValid }
							onClick={ handleVerify }
						>
							{ verifying
								? __( 'Проверка…', 'woodev-plugin-framework' )
								: __( 'Активировать', 'woodev-plugin-framework' )
							}
						</Button>
					</FlexItem>

					{ /* Deactivate button — only when license is currently valid/active;
					     gated on shared busy flag */ }
					{ isValid && (
						<FlexItem>
							<Button
								isDestructive
								disabled={ busy }
								isBusy={ deactivating }
								onClick={ () => setConfirmOpen( true ) }
							>
								{ __( 'Деактивировать', 'woodev-plugin-framework' ) }
							</Button>
						</FlexItem>
					) }

					{ /* Beta versions toggle — gated on shared busy flag */ }
					<FlexItem>
						<ToggleControl
							label={ __( 'Разрешить установку бета-версий', 'woodev-plugin-framework' ) }
							checked={ betaEnabled }
							disabled={ busy }
							onChange={ handleBetaToggle }
						/>
					</FlexItem>

					{ togglingBeta && (
						<FlexItem>
							<Spinner />
						</FlexItem>
					) }
				</Flex>
			</CardFooter>
		</Card>
	);
}
