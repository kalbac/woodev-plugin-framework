/**
 * Setup Wizard — root component.
 *
 * A thin state machine over the PHP-declared steps in a modern WooCommerce
 * onboarding layout: branded header → progress-line stepper → centered card with
 * the current step → in-card action row → footer exit link. The step list
 * INCLUDES the terminal `type==='finish'` step (auto-appended by PHP); the wizard
 * navigates across all of them.
 *
 * - "Продолжить" / "Начать настройку" saves the current settings step (advance on
 *   success only) and advances; on a content/welcome step it just advances.
 * - "Пропустить" skips THIS step (advance WITHOUT saving) — never exits.
 * - Footer link EXITS the wizard: marks it skipped (non-finish) and redirects to
 *   the admin dashboard.
 * - Finish step: marks the wizard completed once, then shows the success screen.
 *
 * All step data + copy come from window.woodevSetupWizard (PHP-driven). Classic
 * JSX runtime: createElement / Fragment used directly.
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment, useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import Stepper from './stepper';
import StepView from './step-view';
import { CheckFilledIcon, GearIcon, StarIcon } from './icons';
import { saveStep, complete } from './rest';

/**
 * Resolves the admin landing URL used by the footer exit link.
 *
 * @return {string} admin URL.
 */
function adminUrl() {
	return window.woodevSetupWizard.adminUrl || '/wp-admin/';
}

/**
 * Wizard root.
 *
 * @return {Object} React element.
 */
export default function App() {
	const {
		steps,
		finishActions,
		finishSecondaryActions,
		pluginName,
		headerLogoUrl,
	} = window.woodevSetupWizard;

	/**
	 * Resolves the initial step index from the URL hash (`#{id}-step`), falling
	 * back to 0 when absent or unrecognized.
	 *
	 * @return {number} initial step index.
	 */
	function initialIndex() {
		const hash = window.location.hash.replace( /^#/, '' );
		const found = steps.findIndex( ( s ) => `${ s.id }-step` === hash );
		return found >= 0 ? found : 0;
	}

	const [ index, setIndex ] = useState( initialIndex );
	const [ values, setValues ] = useState( {} );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const step = steps[ index ];
	const isFinish = 'finish' === step.type;
	const isWelcome = 'content' === step.type && 0 === index;
	const isSettings = 'settings' === step.type;

	// Keep the URL hash in sync with the active step (WooCommerce-style anchor).
	useEffect( () => {
		const target = `#${ step.id }-step`;
		if ( window.location.hash !== target ) {
			window.location.hash = target;
		}
	}, [ index, step.id ] );

	// Navigate when the user edits the hash or uses browser back/forward.
	useEffect( () => {
		function handleHashChange() {
			const hash = window.location.hash.replace( /^#/, '' );
			const found = steps.findIndex( ( s ) => `${ s.id }-step` === hash );
			if ( found >= 0 ) {
				setIndex( ( current ) => ( current === found ? current : found ) );
			}
		}

		window.addEventListener( 'hashchange', handleHashChange );
		return () => window.removeEventListener( 'hashchange', handleHashChange );
	}, [ steps ] );

	/**
	 * Navigates to an arbitrary step index (used by the stepper + Back button).
	 *
	 * @param {number} i target step index.
	 */
	function goTo( i ) {
		setError( null );
		if ( i >= 0 && i < steps.length && i !== index ) {
			setIndex( i );
		}
	}

	// Mark the wizard complete once when the finish step becomes active.
	useEffect( () => {
		if ( isFinish ) {
			complete( 'completed' ).catch( ( e ) => {
				if ( window.console ) {
					window.console.warn( 'woodev setup: complete() failed', e );
				}
			} );
		}
	}, [ isFinish ] );

	/**
	 * Advances to the next step, saving the current settings step first.
	 */
	async function goNext() {
		setError( null );
		setBusy( true );
		try {
			if ( isSettings ) {
				await saveStep( step.id, values[ step.id ] || {} );
			}
			setIndex( index + 1 );
		} catch ( e ) {
			setError( e.message || __( 'Что-то пошло не так. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
		} finally {
			setBusy( false );
		}
	}

	/**
	 * Skips THIS step (advance without saving). Does not exit the wizard.
	 */
	function skipStep() {
		setError( null );
		setIndex( index + 1 );
	}

	/**
	 * Exits the wizard: marks it skipped (non-finish) then redirects to admin.
	 */
	async function exitWizard() {
		if ( ! isFinish ) {
			try {
				await complete( 'skipped' );
			} catch ( e ) {
				// Best-effort: redirect regardless of the completion call result.
			}
		}
		window.location.href = adminUrl();
	}

	const primaryLabel = isWelcome
		? __( 'Начать настройку', 'woodev-plugin-framework' )
		: __( 'Продолжить', 'woodev-plugin-framework' );

	const footerLabel = isWelcome
		? __( 'Не сейчас', 'woodev-plugin-framework' )
		: __( 'Вернуться в Консоль WordPress', 'woodev-plugin-framework' );

	return createElement(
		'div',
		{ className: 'woodev-setup' },
		renderHeader( pluginName, headerLogoUrl ),
		createElement( Stepper, { steps, index, onNavigate: goTo } ),
		isFinish
			? createElement(
				Fragment,
				null,
				renderFinish( pluginName, finishActions, finishSecondaryActions ),
				createElement(
					'div',
					{ className: 'woodev-setup__finish-done' },
					createElement(
						Button,
						{
							variant: 'primary',
							className: 'woodev-setup__primary',
							onClick: () => {
								window.location.href = adminUrl();
							},
						},
						__( 'Готово', 'woodev-plugin-framework' )
					)
				)
			)
			: createElement(
				Fragment,
				null,
				createElement(
					'div',
					{ className: 'woodev-setup__card' },
					error &&
						createElement(
							'div',
							{ className: 'woodev-setup__error', role: 'alert' },
							error
						),
					createElement( StepView, {
						step,
						values: values[ step.id ] || {},
						onChange: ( v ) => setValues( { ...values, [ step.id ]: v } ),
					} ),
					createElement(
						'div',
						{ className: 'woodev-setup__actions' },
						createElement(
							'div',
							{ className: 'woodev-setup__actions-left' },
							index > 0 &&
								createElement(
									Button,
									{
										variant: 'tertiary',
										disabled: busy,
										onClick: () => goTo( index - 1 ),
										className: 'woodev-setup__back',
									},
									__( 'Назад', 'woodev-plugin-framework' )
								)
						),
						createElement(
							'div',
							{ className: 'woodev-setup__actions-right' },
							isSettings && step.skippable !== false &&
								createElement(
									Button,
									{
										variant: 'link',
										disabled: busy,
										onClick: skipStep,
										className: 'woodev-setup__skip',
									},
									__( 'Пропустить', 'woodev-plugin-framework' )
								),
							createElement(
								Button,
								{
									variant: 'primary',
									isBusy: busy,
									disabled: busy,
									onClick: goNext,
									className: 'woodev-setup__primary',
								},
								primaryLabel
							)
						)
					)
				)
			),
		createElement(
			'span',
			{ className: 'woodev-setup__footer' },
			createElement(
				'a',
				{
					href: adminUrl(),
					onClick: ( e ) => {
						e.preventDefault();
						exitWizard();
					},
				},
				footerLabel
			)
		)
	);
}

/**
 * Renders the brand header (plugin logo, or mark + name + subline fallback).
 *
 * @param {string} pluginName    plugin display name.
 * @param {string} headerLogoUrl optional plugin logo URL.
 * @return {Object} React element.
 */
function renderHeader( pluginName, headerLogoUrl ) {
	return createElement(
		'div',
		{ className: 'woodev-setup__brand' },
		headerLogoUrl
			? createElement( 'img', {
				className: 'woodev-setup__brand-logo',
				src: headerLogoUrl,
				alt: pluginName,
			} )
			: createElement(
				Fragment,
				null,
				createElement(
					'span',
					{ className: 'woodev-setup__brand-mark', 'aria-hidden': 'true' },
					createElement(
						'svg',
						{ viewBox: '0 0 24 24', xmlns: 'http://www.w3.org/2000/svg' },
						createElement( 'path', {
							d: 'M3 6h18l-2 13H5L3 6zm2.4 2l1.2 9h10.8l1.2-9H5.4zM9 3h6v2H9V3z',
						} )
					)
				),
				createElement(
					'span',
					{ className: 'woodev-setup__brand-name' },
					pluginName,
					createElement(
						'small',
						null,
						__( 'Мастер настройки', 'woodev-plugin-framework' )
					)
				)
			)
	);
}

/**
 * Renders the completion screen.
 *
 * @param {string} pluginName       plugin display name.
 * @param {Array}  actions          next-step cards.
 * @param {Array}  secondaryActions "also" icon-button actions.
 * @return {Object} React element.
 */
function renderFinish( pluginName, actions, secondaryActions ) {
	const cards = actions || [];
	const also = secondaryActions || [];

	return createElement(
		'div',
		{ className: 'woodev-setup__card' },
		createElement(
			'div',
			{ className: 'woodev-setup__finish-hero' },
			createElement(
				'span',
				{ className: 'woodev-setup__finish-check' },
				createElement( CheckFilledIcon )
			),
			createElement(
				'h1',
				{ className: 'woodev-setup__step-title woodev-setup__finish-title' },
				sprintf(
					/* translators: %s plugin name */
					__( 'Плагин «%s» готов к работе!', 'woodev-plugin-framework' ),
					pluginName
				)
			)
		),
		createElement(
			'p',
			{ className: 'woodev-setup__finish-intro' },
			__( 'Плагин подключён и настроен. Все параметры можно изменить позже на странице плагина.', 'woodev-plugin-framework' )
		),
		( cards.length > 0 || also.length > 0 ) &&
			createElement(
				'ul',
				{ className: 'woodev-setup__next-steps' },
				cards.map( ( action, i ) =>
					createElement(
						'li',
						{ key: i },
						createElement(
							'div',
							{ className: 'woodev-setup__ns-desc' },
							createElement( 'p', { className: 'woodev-setup__ns-heading' }, action.heading ),
							createElement( 'h3', { className: 'woodev-setup__ns-title' }, action.title ),
							action.description &&
								createElement( 'p', { className: 'woodev-setup__ns-extra' }, action.description )
						),
						createElement(
							'div',
							{ className: 'woodev-setup__ns-action' },
							createElement(
								Button,
								{ variant: 'secondary', href: action.url, className: 'woodev-setup__secondary' },
								action.actionLabel
							)
						)
					)
				),
				also.length > 0 &&
					createElement(
						'li',
						{ className: 'woodev-setup__also' },
						createElement(
							'span',
							{ className: 'woodev-setup__also-label' },
							__( 'Вы также можете:', 'woodev-plugin-framework' )
						),
						also.map( ( action, i ) =>
							createElement(
								'a',
								{ key: i, href: action.url, className: 'woodev-setup__btn-icon' },
								'review' === action.icon ? createElement( StarIcon ) : createElement( GearIcon ),
								action.label
							)
						)
					)
			)
	);
}
