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
 * - The stepper is back-free but forward-gated (#110): a step label is a button only for
 *   a step already VISITED in this session (index <= the furthest one reached) and never
 *   for the terminal finish step, which is reachable only through the primary button of
 *   the last real step. The same gate covers `#{id}-step` hash navigation. The server has
 *   no per-step completion state, so the visited boundary lives in the tab's
 *   sessionStorage (per plugin): a reload resumes at the hash step but never beyond the
 *   furthest step reached, so a deep link cannot skip the steps in between.
 * - Footer link EXITS the wizard: marks it skipped (non-finish) and redirects to
 *   the admin dashboard.
 * - Finish step: marks the wizard completed once, then shows the success screen. When that
 *   write fails the screen stays (the settings were saved step by step) but an alert says the
 *   completion was NOT recorded and offers a retry — it never pretends (#1047).
 * - After every step change focus moves to the new step's heading (not on first load).
 *
 * All step data + copy come from window.woodevSetupWizard (PHP-driven). Classic
 * JSX runtime: createElement / Fragment used directly.
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment, useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import Stepper from '../components/stepper';
import StepView from './step-view';
import { CheckFilledIcon, GearIcon, StarIcon } from '../components/icons';
import { saveStep, complete } from './rest';
import { validateFields, isFieldVisible } from '../components/validate';

/**
 * Resolves the admin landing URL used by the footer exit link.
 *
 * @return {string} admin URL.
 */
function adminUrl() {
	return window.woodevSetupWizard.adminUrl || '/wp-admin/';
}

/**
 * sessionStorage key of the furthest visited step index for this plugin's wizard.
 *
 * @param {string} pluginId plugin id from the bootstrap.
 * @return {string} storage key.
 */
function visitedKey( pluginId ) {
	return `woodevSetupWizard:visited:${ pluginId || '' }`;
}

/**
 * Reads the furthest visited step from storage, by step id; 0 (nothing visited
 * beyond the first step) when storage is unavailable, empty, or names a step the
 * current list does not have (a plugin update removed it, or the value is garbage)
 * — an index would silently point at a different step once the list changes.
 *
 * @param {string} pluginId plugin id from the bootstrap.
 * @param {Array}  steps    the current step list.
 * @return {number} furthest visited step index.
 */
function readVisited( pluginId, steps ) {
	try {
		const raw = window.sessionStorage.getItem( visitedKey( pluginId ) );
		const found = null === raw ? -1 : steps.findIndex( ( s ) => String( s.id ) === raw );
		return found > 0 ? found : 0;
	} catch ( e ) {
		return 0;
	}
}

/**
 * Stores the furthest visited step's id (best-effort: storage may be blocked).
 *
 * @param {string} pluginId plugin id from the bootstrap.
 * @param {string} stepId   id of the furthest visited step.
 */
function writeVisited( pluginId, stepId ) {
	try {
		window.sessionStorage.setItem( visitedKey( pluginId ), String( stepId ) );
	} catch ( e ) {
		// Unavailable storage just means the boundary is not remembered across reloads.
	}
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
		pluginId,
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
		if ( found < 0 ) {
			return 0;
		}
		// A deep link (or reload) may resume a step, but never lands on the finish
		// step — that one is reached only through the last real step's «Продолжить».
		const wanted = 'finish' === steps[ found ].type ? Math.max( 0, found - 1 ) : found;
		// …and never beyond the furthest step this tab has actually visited, so a
		// hand-typed or bookmarked hash cannot seed the boundary past unvisited steps.
		return Math.min( wanted, readVisited( pluginId, steps ) );
	}

	const [ index, setIndex ] = useState( initialIndex );
	// Furthest step index reached in this tab; monotonic, so writing it during render
	// is idempotent. Seeded once from sessionStorage (the stored boundary may sit
	// beyond the resumed step) and mirrored by `indexRef` for the hashchange listener,
	// which must not close over a stale value.
	const maxVisitedRef = useRef( null );
	if ( null === maxVisitedRef.current ) {
		maxVisitedRef.current = Math.max( index, readVisited( pluginId, steps ) );
	}
	const indexRef = useRef( index );
	maxVisitedRef.current = Math.max( maxVisitedRef.current, index );
	indexRef.current = index;
	const maxVisited = maxVisitedRef.current;
	const [ values, setValues ] = useState( {} );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ showErrors, setShowErrors ] = useState( false );
	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const [ errorRevealGen, setErrorRevealGen ] = useState( 0 );
	// The finish step's «completed» write failed / the footer exit's «skipped» write failed.
	const [ completeFailed, setCompleteFailed ] = useState( false );
	const [ completeRetrying, setCompleteRetrying ] = useState( false );
	const [ exitFailed, setExitFailed ] = useState( false );
	const rootRef = useRef( null );
	const firstRenderRef = useRef( true );

	const step = steps[ index ];
	const isFinish = 'finish' === step.type;
	const isWelcome = 'content' === step.type && 0 === index;
	const isSettings = 'settings' === step.type;

	// Remember the visited boundary for a reload in this tab.
	useEffect( () => {
		writeVisited( pluginId, steps[ maxVisited ].id );
	}, [ pluginId, steps, maxVisited ] );

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
			if ( found < 0 ) {
				return;
			}
			if ( ! canReach( found, maxVisitedRef.current ) ) {
				// Forward past the visited range (or onto finish) by hand-edited hash:
				// refuse and put the hash back on the current step.
				window.location.hash = `#${ steps[ indexRef.current ].id }-step`;
				return;
			}
			setIndex( ( current ) => ( current === found ? current : found ) );
		}

		window.addEventListener( 'hashchange', handleHashChange );
		return () => window.removeEventListener( 'hashchange', handleHashChange );
	}, [ steps ] );

	/**
	 * Navigates to an arbitrary step index (used by the stepper + Back button; the
	 * stepper only offers reachable ones, see `canReach`).
	 *
	 * @param {number} i target step index.
	 */
	function goTo( i ) {
		setError( null );
		setExitFailed( false );
		setShowErrors( false );
		setFieldErrors( {} );
		if ( i >= 0 && i < steps.length && i !== index ) {
			setIndex( i );
		}
	}

	/**
	 * Records the wizard as completed; on failure raises `completeFailed` so the finish
	 * screen says so instead of showing an unqualified success (#1047).
	 */
	function markCompleted() {
		setCompleteRetrying( true );
		return complete( 'completed' )
			.then( () => setCompleteFailed( false ) )
			.catch( ( e ) => {
				setCompleteFailed( true );
				if ( window.console ) {
					window.console.warn( 'woodev setup: complete() failed', e );
				}
			} )
			.then( () => setCompleteRetrying( false ) );
	}

	// Mark the wizard complete once when the finish step becomes active.
	useEffect( () => {
		if ( isFinish ) {
			markCompleted();
		}
	}, [ isFinish ] );

	// Move focus to the new step's heading after every step change, so a keyboard / screen
	// reader user lands on the new content instead of on a button that no longer exists.
	// Skipped on the first render: loading the page must not steal focus (#1047).
	useEffect( () => {
		if ( firstRenderRef.current ) {
			firstRenderRef.current = false;
			return;
		}
		const heading = rootRef.current && rootRef.current.querySelector( '.woodev-setup__step-title' );
		if ( heading ) {
			heading.focus();
		}
	}, [ index ] );

	// Scroll to the first invalid field and focus its control whenever validation
	// errors are revealed (client-side block or server-side 400 reject).
	useEffect( () => {
		if ( ! errorRevealGen ) {
			return;
		}
		const el = document.querySelector( '.woodev-setup .woodev-field--error' );
		if ( el ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			const control = el.querySelector( 'input, textarea, button' );
			if ( control ) {
				control.focus( { preventScroll: true } );
			}
		}
	}, [ errorRevealGen ] );

	/**
	 * Whether the stepper / hash may take the user to step `i`: any step up to the
	 * furthest one visited, but never the terminal finish step.
	 *
	 * @param {number} i          target step index.
	 * @param {number} furthest   furthest visited step index.
	 * @return {boolean} true when navigation is allowed.
	 */
	function canReach( i, furthest ) {
		return i <= furthest && !! steps[ i ] && 'finish' !== steps[ i ].type;
	}

	/**
	 * Advances to the next step, saving the current settings step first.
	 *
	 * For settings steps, client-side validation runs before the save request.
	 * If any field is invalid the reveal state is set and the advance is blocked.
	 * On a server 400, `err.data.errors` is mapped to per-field error state.
	 *
	 * @since 2.0.2
	 */
	async function goNext() {
		setError( null );
		setExitFailed( false );

		if ( isSettings ) {
			const stepValues = {};
			Object.keys( step.fields || {} ).forEach( ( id ) => {
				stepValues[ id ] = ( values[ step.id ] || {} )[ id ] ?? step.fields[ id ].value;
			} );

			const visibleFields = {};
			Object.keys( step.fields || {} ).forEach( ( id ) => {
				if ( isFieldVisible( step.fields[ id ], stepValues ) ) {
					visibleFields[ id ] = step.fields[ id ];
				}
			} );

			const clientErrors = validateFields( visibleFields, stepValues, values[ step.id ] || {} );
			if ( Object.keys( clientErrors ).length > 0 ) {
				setShowErrors( true );
				setFieldErrors( {} ); // clear stale server errors before revealing fresh client errors
				setError( __( 'Проверьте правильность заполнения полей на этом шаге.', 'woodev-plugin-framework' ) );
				setErrorRevealGen( ( g ) => g + 1 );
				return; // block advance — reveal fresh client errors + summary
			}
		}

		setBusy( true );
		try {
			if ( isSettings ) {
				await saveStep( step.id, values[ step.id ] || {} );
			}
			setShowErrors( false );
			setFieldErrors( {} );
			setIndex( ( prev ) => prev + 1 );
		} catch ( e ) {
			const map = e && e.data && e.data.errors ? e.data.errors : null;
			if ( map ) {
				setFieldErrors( map );
				setShowErrors( true );
				setErrorRevealGen( ( g ) => g + 1 );
			}
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
		setExitFailed( false );
		setShowErrors( false );
		setFieldErrors( {} );
		setIndex( index + 1 );
	}

	/**
	 * Exits the wizard: marks it skipped (non-finish) then redirects to admin.
	 */
	async function exitWizard() {
		// A second click after a failure leaves anyway: the merchant is never trapped in the
		// wizard by a broken endpoint, but the first failure is not swallowed (#1047).
		if ( ! isFinish && ! exitFailed ) {
			try {
				await complete( 'skipped' );
			} catch ( e ) {
				setExitFailed( true );
				setError( __( 'Не удалось запомнить, что мастер пропущен, — он может открыться снова. Нажмите ссылку ещё раз, чтобы выйти всё равно.', 'woodev-plugin-framework' ) );
				return;
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
		{ className: 'woodev-setup', ref: rootRef },
		renderHeader( pluginName, headerLogoUrl ),
		createElement( Stepper, {
			steps,
			index,
			onNavigate: goTo,
			disabled: busy,
			canNavigate: ( i ) => canReach( i, maxVisited ),
		} ),
		isFinish
			? createElement(
				Fragment,
				null,
				completeFailed &&
					createElement(
						'div',
						{ className: 'woodev-setup__error woodev-setup__error--finish', role: 'alert' },
						createElement(
							'span',
							null,
							__( 'Настройки сохранены, но отметить мастер завершённым не удалось — при следующем визите он может открыться снова.', 'woodev-plugin-framework' )
						),
						' ',
						createElement(
							Button,
							{
								variant: 'link',
								disabled: completeRetrying,
								onClick: markCompleted,
								className: 'woodev-setup__retry',
							},
							__( 'Повторить', 'woodev-plugin-framework' )
						)
					),
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
						// Keyed by step so moving between steps is a real unmount +
						// mount, not a reuse of the previous step's field instances.
						// Without it, two steps declaring the SAME field id reuse one
						// instance, and a control that seeds itself once on mount —
						// `WizardRichText`, which cannot feed `value` back into its
						// contentEditable on every render without resetting the caret —
						// would still show the previous step's content (#783). Mirrors
						// `SectionView`'s `key={ tab.id:section.id }` on the settings page.
						key: step.id,
						step,
						values: values[ step.id ] || {},
						onChange: ( v ) => {
							if ( Object.keys( fieldErrors ).length > 0 ) {
								setFieldErrors( {} );
							}
							setValues( { ...values, [ step.id ]: v } );
						},
						showErrors,
						serverErrors: fieldErrors,
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
				{ className: 'woodev-setup__step-title woodev-setup__finish-title', tabIndex: -1 },
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
