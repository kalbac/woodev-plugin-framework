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
 * - "Пропустить" skips THIS step (advance WITHOUT saving) — never exits. A step declared
 *   `skippable: false` by PHP has no such control.
 * - Step actions (D2): buttons for the step's server-side operations («Проверить ключ»…). An
 *   action sends the merchant's unsaved edits (the server adds the stored values) and shows the answer; a
 *   `destructive` one first asks the merchant to confirm, and is sent with `confirmed: true`.
 * - The stepper is back-free but forward-gated (#110): a step label is a button only for
 *   a step already VISITED in this session (index <= the furthest one reached) and never
 *   for the terminal finish step, which is reachable only through the primary button of
 *   the last real step. The same gate covers `#{id}-step` hash navigation. The server has
 *   no per-step completion state, so the visited boundary lives in the tab's
 *   sessionStorage (per plugin): a reload resumes at the hash step but never beyond the
 *   furthest step reached, so a deep link cannot skip the steps in between.
 * - The step list is the server's step GRAPH (D3): steps the server-side predicates hide are
 *   not shown, and every successful save or action returns the recomputed graph, which the
 *   client re-renders from (no reaction to unsaved form values). The current step is kept by id.
 * - A step may declare a plugin component (D4), rendered inside the standard frame with the
 *   plumbing in `types.ts` (values, onChange, save, next, runAction, errors, busy…).
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
import StepComponentBoundary from './step-component-boundary';
import { resolveStepComponent } from './component-registry';
import { CheckFilledIcon, GearIcon, StarIcon } from '../components/icons';
import { saveStep, complete, runAction } from './rest';
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
 * The steps to show: the graph minus the ones the server hides now.
 *
 * @param {Array} graph the step graph (bootstrap `steps` or a REST response's `graph`).
 * @return {Array} visible step descriptors, in order.
 */
function visibleSteps( graph ) {
	return ( graph || [] ).filter( ( s ) => false !== s.visible );
}

/**
 * First visible entry that comes AFTER `id` in the full, ordered graph (hidden entries included).
 *
 * @param {Array}  full the whole graph, visible and hidden entries in server order.
 * @param {string} id   a step id.
 * @return {Object|null} the entry, or null when `id` is last / not in the graph.
 */
function visibleAfter( full, id ) {
	const at = full.findIndex( ( s ) => s.id === id );
	return at < 0 ? null : full.slice( at + 1 ).find( ( s ) => false !== s.visible ) || null;
}

/**
 * Last visible entry that comes BEFORE `id` in the full, ordered graph.
 *
 * @param {Array}  full the whole graph.
 * @param {string} id   a step id.
 * @return {Object|null} the entry, or null.
 */
function visibleBefore( full, id ) {
	const at = full.findIndex( ( s ) => s.id === id );
	return at < 0 ? null : full.slice( 0, at ).reverse().find( ( s ) => false !== s.visible ) || null;
}

/**
 * Structural equality for field values (scalars, arrays, plain objects).
 *
 * @param {*} a first value.
 * @param {*} b second value.
 * @return {boolean} true when equal.
 */
function sameValue( a, b ) {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Wizard root.
 *
 * @return {Object} React element.
 */
export default function App() {
	const {
		finishActions,
		finishSecondaryActions,
		pluginName,
		headerLogoUrl,
		pluginId,
	} = window.woodevSetupWizard;

	// The visible part of the step graph; replaced whenever a save or action returns a new graph.
	const [ steps, setSteps ] = useState( () => visibleSteps( window.woodevSetupWizard.steps ) );

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
	const stepsRef = useRef( steps );
	maxVisitedRef.current = Math.max( maxVisitedRef.current, index );
	indexRef.current = index;
	stepsRef.current = steps;
	const maxVisited = maxVisitedRef.current;
	const [ values, setValues ] = useState( {} );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ showErrors, setShowErrors ] = useState( false );
	const [ fieldErrors, setFieldErrors ] = useState( {} );
	const [ errorRevealGen, setErrorRevealGen ] = useState( 0 );
	// Step actions: the one running, the destructive one awaiting confirmation, the last answer.
	const [ actionBusy, setActionBusy ] = useState( null );
	const [ pendingConfirm, setPendingConfirm ] = useState( null );
	const [ actionResult, setActionResult ] = useState( null );
	const actionGenRef = useRef( 0 );
	// Generation of the latest request that can return a step graph (a save or an action). A
	// response applies its graph only while it is still the latest: an older one describes a state
	// that a newer request has already replaced.
	const graphReqRef = useRef( 0 );
	// A custom component's pending destructive action: settled when the merchant answers the confirmation.
	const componentResolveRef = useRef( null );
	// The finish step's «completed» write: 'pending' → 'done' | 'failed' (only 'done' may show the
	// success screen); the footer exit's «skipped» write may also fail.
	const [ completeStatus, setCompleteStatus ] = useState( 'pending' );
	const [ exitFailed, setExitFailed ] = useState( false );
	// The footer exit's «skipped» write is in flight (a ref, so a second click in the same
	// tick is already refused; the state mirrors it into `aria-disabled`).
	const exitingRef = useRef( false );
	const [ exiting, setExiting ] = useState( false );
	const rootRef = useRef( null );
	const firstRenderRef = useRef( true );

	// `|| last` guards the instant between a graph arriving and the index following it.
	const step = steps[ index ] || steps[ steps.length - 1 ];
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
			if ( found !== indexRef.current ) {
				// A real step change by hash / back / forward starts a fresh footer-exit attempt,
				// exactly like `goTo`: an earlier failure must not pre-authorise leaving.
				setError( null );
				setExitFailed( false );
				setShowErrors( false );
				setFieldErrors( {} );
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
	 * Records the wizard as completed. The success screen is shown only once the write
	 * succeeded; a failure turns the finish step into an honest error with a retry (#1047, audit #5).
	 */
	function markCompleted() {
		setCompleteStatus( 'pending' );
		return complete( 'completed' )
			.then( () => setCompleteStatus( 'done' ) )
			.catch( ( e ) => {
				setCompleteStatus( 'failed' );
				if ( window.console ) {
					window.console.warn( 'woodev setup: complete() failed', e );
				}
			} );
	}

	// Mark the wizard complete once when the finish step becomes active.
	useEffect( () => {
		if ( isFinish ) {
			markCompleted();
		}
	}, [ isFinish ] );

	// The finish heading is replaced when the «completed» write settles (neutral → success or
	// error), so focus follows it to the new element.
	useEffect( () => {
		if ( isFinish && 'pending' !== completeStatus ) {
			const heading = rootRef.current && rootRef.current.querySelector( '.woodev-setup__finish-title' );
			if ( heading ) {
				heading.focus();
			}
		}
	}, [ isFinish, completeStatus ] );

	// An action's answer and a pending confirmation belong to the step they were raised on
	// (keyed by the step's id, not its position: a graph change can move the same step).
	useEffect( () => {
		actionGenRef.current += 1; // a request still in flight belongs to the step we just left.
		settleComponentAction( { status: 'cancelled', message: '', data: {} } );
		setActionBusy( null );
		setPendingConfirm( null );
		setActionResult( null );
	}, [ step.id ] );

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
	}, [ step.id ] );

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
	 * The current step's field values: what the merchant typed, else the schema default.
	 *
	 * @return {Object} field id => value.
	 */
	function collectStepValues() {
		const stepValues = {};
		Object.keys( step.fields || {} ).forEach( ( id ) => {
			stepValues[ id ] = ( values[ step.id ] || {} )[ id ] ?? step.fields[ id ].value;
		} );
		return stepValues;
	}

	/**
	 * The values a `show_if` condition is evaluated against: the SAVED value of every field on
	 * every step of the graph, overlaid with the current step's own edits. A condition may depend
	 * on a field another step owns (a key shown only for the mode picked earlier), and the server
	 * resolves it the same way — the submitted value when present, else the stored one.
	 *
	 * @return {Object} field id => value.
	 */
	function collectConditionValues() {
		const context = {};
		steps.forEach( ( s ) => {
			if ( s.id === step.id ) {
				return;
			}
			Object.keys( s.fields || {} ).forEach( ( id ) => {
				context[ id ] = s.fields[ id ].value;
			} );
		} );
		return { ...context, ...collectStepValues() };
	}

	/**
	 * Settles the promise a custom component is waiting on for a destructive action.
	 *
	 * @param {Object} answer the action's answer, or `{ status: 'cancelled' }`.
	 */
	function settleComponentAction( answer ) {
		const resolve = componentResolveRef.current;
		componentResolveRef.current = null;
		if ( resolve ) {
			resolve( answer );
		}
	}

	/**
	 * Re-renders from a step graph the server returned after a save or action (D3).
	 *
	 * - Applied only if `requestGen` is still the latest graph-bearing request (an obsolete response
	 *   must not bring back a branch a newer save hid).
	 * - Navigation is by step ORDER and id, never by the old numeric index: after an `advanceFrom`
	 *   save the merchant lands on the first visible step that follows it in the full graph (hidden
	 *   entries keep their place), so hiding the current step and earlier ones cannot jump past a
	 *   visible step. If the current step vanished otherwise (an action hid it), the nearest
	 *   visible neighbour is used and the wizard never slides into «finish» from there.
	 * - The visited boundary moves to the last visible step at or before the old boundary.
	 * - Edits that the server's answer has superseded are dropped: a field whose SAVED value changed
	 *   (an action reset it, on_save normalised it) and, when the action says so, the whole step's
	 *   edits. An edit made after the request began is kept.
	 *
	 * @param {Array}  graph                the graph from the response (ignored when absent or malformed).
	 * @param {Object} options
	 * @param {string} options.advanceFrom  step id the merchant is leaving after a successful save.
	 * @param {number} options.requestGen   generation of the request this response answers.
	 * @param {Object} options.startValues  the edits as they were when the request started.
	 * @param {string} options.stepId       step the request belonged to.
	 * @param {true|string[]|undefined} options.discard the action's `discard_edits` answer.
	 * @return {Array|null} the new visible list, or null when nothing was applied.
	 */
	function applyGraph( graph, options = {} ) {
		const { advanceFrom = null, requestGen = null, startValues = null, stepId = null, discard = null } = options;

		if ( null !== requestGen && requestGen !== graphReqRef.current ) {
			return null;
		}

		const full = Array.isArray( graph ) ? graph : [];
		const next = visibleSteps( full );
		// A usable graph always ends with the terminal finish step.
		if ( ! next.length || 'finish' !== next[ next.length - 1 ].type ) {
			return null;
		}

		const old = stepsRef.current;
		const oldCurrentId = old[ indexRef.current ] && old[ indexRef.current ].id;
		const oldFurthestId = old[ maxVisitedRef.current ] && old[ maxVisitedRef.current ].id;
		const indexOf = ( id ) => next.findIndex( ( s ) => s.id === id );
		const inGraph = ( id ) => full.some( ( s ) => s.id === id );

		let target = -1;
		if ( advanceFrom && inGraph( advanceFrom ) ) {
			const successor = visibleAfter( full, advanceFrom );
			target = successor ? indexOf( successor.id ) : -1;
		} else if ( indexOf( oldCurrentId ) >= 0 ) {
			target = indexOf( oldCurrentId );
		} else if ( inGraph( oldCurrentId ) ) {
			const successor = visibleAfter( full, oldCurrentId );
			const predecessor = visibleBefore( full, oldCurrentId );
			const pick = successor && 'finish' !== successor.type ? successor : predecessor || successor;
			target = pick ? indexOf( pick.id ) : -1;
		}
		if ( target < 0 ) {
			target = Math.min( indexRef.current + ( advanceFrom ? 1 : 0 ), next.length - 1 );
		}

		let furthest = 0;
		const furthestAt = full.findIndex( ( s ) => s.id === oldFurthestId );
		for ( let i = 0; i <= furthestAt; i++ ) {
			if ( false !== full[ i ].visible && indexOf( full[ i ].id ) >= 0 ) {
				furthest = indexOf( full[ i ].id );
			}
		}

		// Edits the answer supersedes.
		const drops = [];
		old.forEach( ( o ) => {
			const n = next.find( ( s ) => s.id === o.id );
			if ( ! n ) {
				return;
			}
			Object.keys( n.fields || {} ).forEach( ( f ) => {
				const before = o.fields && o.fields[ f ] ? o.fields[ f ].value : undefined;
				if ( ! sameValue( before, n.fields[ f ].value ) ) {
					drops.push( [ o.id, f ] );
				}
			} );
		} );
		if ( stepId && startValues && discard ) {
			( true === discard ? Object.keys( startValues[ stepId ] || {} ) : [].concat( discard ) ).forEach( ( f ) => {
				drops.push( [ stepId, f ] );
			} );
		}
		if ( drops.length && startValues ) {
			setValues( ( current ) => {
				const out = { ...current };
				drops.forEach( ( [ sid, f ] ) => {
					const edits = out[ sid ];
					const started = startValues[ sid ];
					// Only an edit the merchant has not touched since the request began.
					if ( edits && f in edits && started && f in started && sameValue( edits[ f ], started[ f ] ) ) {
						const copy = { ...edits };
						delete copy[ f ];
						out[ sid ] = copy;
					}
				} );
				return out;
			} );
		}

		stepsRef.current = next;
		indexRef.current = target;
		maxVisitedRef.current = Math.min( Math.max( furthest, target ), next.length - 1 );
		setSteps( next );
		setIndex( target );

		return next;
	}

	/**
	 * Runs a step action. A destructive one first raises the confirmation; the confirmed
	 * call is the one that reaches the server (`confirmed: true`).
	 *
	 * @param {Object}  action    action descriptor from the bootstrap (id, label, destructive, confirm).
	 * @param {boolean} confirmed whether the merchant already confirmed.
	 * @return {Promise<Object|null>} the action's answer `{ status, message, data }`; null when it
	 *                                only raised the confirmation.
	 */
	async function runStepAction( action, confirmed = false ) {
		setError( null );
		setActionResult( null );

		if ( action.destructive && ! confirmed ) {
			setPendingConfirm( action.id );
			return null;
		}

		setPendingConfirm( null );
		setActionBusy( action.id );
		// This request's generation: navigating to another step or starting a newer action
		// bumps the ref, and whatever this request resolves with afterwards is dropped.
		const generation = ++actionGenRef.current;
		const isCurrent = () => generation === actionGenRef.current;
		const requestGen = ++graphReqRef.current;
		const startValues = values;
		const stepId = step.id;
		let result;
		try {
			// Only the merchant's edits travel; the server lays them over the stored values
			// (a masked secret the merchant did not retype must not arrive as '').
			const answer = await runAction( stepId, action.id, values[ stepId ] || {}, confirmed );
			// The action may have persisted something that changes the graph — apply it even when
			// the answer itself is stale for display (the server state is what it is), but not when
			// a newer save or action has superseded this request.
			applyGraph( answer && answer.graph, {
				requestGen,
				startValues,
				stepId,
				discard: answer && answer.discard_edits,
			} );
			result = {
				status: answer && 'error' === answer.status ? 'error' : 'success',
				message: ( answer && answer.message ) || '',
				data: ( answer && answer.data ) || {},
			};
		} catch ( e ) {
			result = {
				status: 'error',
				message: e.message || __( 'Что-то пошло не так. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
				data: {},
			};
		}

		if ( isCurrent() ) {
			setActionResult( { actionId: action.id, ...result } );
			setActionBusy( null );
		}

		return result;
	}

	/**
	 * Saves the current step on the server and, with `advance`, moves on.
	 *
	 * For settings steps, client-side validation runs before the request; invalid fields reveal
	 * their errors and block. A settings step always asks the server (validate → persist the edited
	 * fields → on_save); a content step only when PHP says it validates, or when `force`d (a custom
	 * component's own `save()`). On a server refusal `err.data.errors` is mapped to per-field
	 * errors. A success returns the recomputed step graph, which replaces the list (D3).
	 *
	 * @since 2.0.2
	 *
	 * @param {Object}  options
	 * @param {boolean} options.advance move to the next visible step after a success.
	 * @param {boolean} options.force   ask the server even for a content step without a validator.
	 * @return {Promise<boolean>} true when the step was accepted.
	 */
	async function submitStep( { advance = true, force = false } = {} ) {
		setError( null );
		setExitFailed( false );

		if ( isSettings ) {
			const stepValues = collectStepValues();
			const conditionValues = collectConditionValues();

			const visibleFields = {};
			Object.keys( step.fields || {} ).forEach( ( id ) => {
				if ( isFieldVisible( step.fields[ id ], conditionValues ) ) {
					visibleFields[ id ] = step.fields[ id ];
				}
			} );

			const clientErrors = validateFields( visibleFields, stepValues, values[ step.id ] || {} );
			if ( Object.keys( clientErrors ).length > 0 ) {
				setShowErrors( true );
				setFieldErrors( {} ); // clear stale server errors before revealing fresh client errors
				setError( __( 'Проверьте правильность заполнения полей на этом шаге.', 'woodev-plugin-framework' ) );
				setErrorRevealGen( ( g ) => g + 1 );
				return false; // block advance — reveal fresh client errors + summary
			}
		}

		setBusy( true );
		try {
			let response = null;
			let requestGen = null;
			const startValues = values;
			if ( force || isSettings || step.validates ) {
				requestGen = ++graphReqRef.current;
				response = await saveStep( step.id, values[ step.id ] || {} );
			}
			setShowErrors( false );
			setFieldErrors( {} );

			// The graph decides where «next» is: the first visible step after this one in the full
			// ordered graph (applyGraph moves the index). Without a usable graph, plain +1.
			const applied = response
				? applyGraph( response.graph, {
					advanceFrom: advance ? step.id : null,
					requestGen,
					startValues,
					stepId: step.id,
				} )
				: null;
			if ( advance && ! applied ) {
				setIndex( ( prev ) => prev + 1 );
			}

			return true;
		} catch ( e ) {
			const map = e && e.data && e.data.errors ? e.data.errors : null;
			if ( map ) {
				setFieldErrors( map );
				setShowErrors( true );
				setErrorRevealGen( ( g ) => g + 1 );
			}
			// An error the validator put on a key that is not a rendered field has nowhere
			// to show but the banner.
			const fieldIds = Object.keys( step.fields || {} );
			const base = ( e && e.message ) || __( 'Что-то пошло не так. Попробуйте ещё раз.', 'woodev-plugin-framework' );
			const extra = map
				? Object.keys( map )
					.filter( ( key ) => ! fieldIds.includes( key ) && map[ key ] !== base )
					.map( ( key ) => map[ key ] )
				: [];
			setError( [ base, ...extra ].join( ' ' ) );
			return false;
		} finally {
			setBusy( false );
		}
	}

	/**
	 * Continue / «Начать настройку»: save when the step needs it, then advance.
	 */
	function goNext() {
		return submitStep( { advance: true } );
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
		if ( exitingRef.current ) {
			return;
		}
		if ( ! isFinish && ! exitFailed ) {
			exitingRef.current = true;
			setExiting( true );
			try {
				await complete( 'skipped' );
			} catch ( e ) {
				exitingRef.current = false;
				setExiting( false );
				setExitFailed( true );
				setError( __( 'Не удалось запомнить, что мастер пропущен, — он может открыться снова. Нажмите ссылку ещё раз, чтобы выйти всё равно.', 'woodev-plugin-framework' ) );
				return;
			}
		}
		window.location.href = adminUrl();
	}

	/**
	 * The step's action buttons, the pending confirmation and the last answer.
	 *
	 * @return {Object|null} React element, or null when the step has no actions.
	 */
	function renderStepActions() {
		const actions = step.actions || [];
		if ( 0 === actions.length ) {
			return null;
		}

		const pending = actions.find( ( a ) => a.id === pendingConfirm );

		return createElement(
			'div',
			{ className: 'woodev-setup__step-actions' },
			// A custom component owns its own controls and starts actions through `runAction`; the
			// framework still shows the confirmation and the answer below.
			! step.component &&
				createElement(
				'div',
				{ className: 'woodev-setup__step-actions-row' },
				actions.map( ( action ) =>
					createElement(
						Button,
						{
							key: action.id,
							variant: 'secondary',
							isDestructive: !! action.destructive,
							isBusy: actionBusy === action.id,
							disabled: busy || null !== actionBusy,
							onClick: () => runStepAction( action ),
							className: 'woodev-setup__step-action',
						},
						action.label
					)
				)
			),
			pending &&
				createElement(
					'div',
					{ className: 'woodev-setup__confirm', role: 'alert' },
					createElement(
						'p',
						null,
						pending.confirm || __( 'Это действие нельзя отменить. Выполнить?', 'woodev-plugin-framework' )
					),
					createElement(
						Button,
						{
							variant: 'secondary',
							isDestructive: true,
							onClick: () => runStepAction( pending, true ).then( settleComponentAction ),
							className: 'woodev-setup__confirm-yes',
						},
						__( 'Да, выполнить', 'woodev-plugin-framework' )
					),
					' ',
					createElement(
						Button,
						{
							variant: 'tertiary',
							onClick: () => {
								setPendingConfirm( null );
								settleComponentAction( { status: 'cancelled', message: '', data: {} } );
							},
							className: 'woodev-setup__confirm-no',
						},
						__( 'Отмена', 'woodev-plugin-framework' )
					)
				),
			actionResult &&
				actionResult.message &&
				createElement(
					'div',
					{
						className: `woodev-setup__action-result woodev-setup__action-result--${ actionResult.status }`,
						role: 'error' === actionResult.status ? 'alert' : 'status',
					},
					actionResult.message
				)
		);
	}

	/**
	 * The terminal step. The success screen appears only after «completed» was persisted: while
	 * the write is in flight the heading is neutral, and a failure shows an error with a retry
	 * instead of «ready» (audit #5).
	 *
	 * @return {Object} React element.
	 */
	function renderFinishStep() {
		if ( 'done' === completeStatus ) {
			return createElement(
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
			);
		}

		const failed = 'failed' === completeStatus;

		return createElement(
			'div',
			{ className: 'woodev-setup__card' },
			failed &&
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
							onClick: markCompleted,
							className: 'woodev-setup__retry',
						},
						__( 'Повторить', 'woodev-plugin-framework' )
					)
				),
			createElement(
				'h1',
				{ className: 'woodev-setup__step-title woodev-setup__finish-title', tabIndex: -1 },
				failed
					? __( 'Мастер ещё не завершён', 'woodev-plugin-framework' )
					: __( 'Завершаем настройку…', 'woodev-plugin-framework' )
			),
			! failed &&
				createElement( 'p', { className: 'woodev-setup__finish-intro', role: 'status' }, __( 'Сохраняем отметку о завершении.', 'woodev-plugin-framework' ) )
		);
	}

	/**
	 * The body of a step that declares a plugin component (D4), or an honest error state when the
	 * component is not there.
	 *
	 * @return {Object} React element.
	 */
	function renderComponentBody() {
		const Resolved = resolveStepComponent( step.component );
		const failure = createElement(
			'div',
			{ className: 'woodev-setup__error woodev-setup__component-error', role: 'alert' },
			__( 'Не удалось загрузить содержимое этого шага. Обновите страницу; если ошибка повторится, обратитесь к разработчику плагина.', 'woodev-plugin-framework' )
		);

		if ( ! Resolved ) {
			if ( window.console ) {
				window.console.error(
					`woodev setup: step "${ step.id }" component "${ step.component.export }" of script "${ step.component.handle }" is not published`
				);
			}
			return failure;
		}

		return createElement(
			StepComponentBoundary,
			{ fallback: failure },
			createElement( Resolved, {
				step,
				values: values[ step.id ] || {},
				onChange: onStepChange,
				errors: fieldErrors,
				showErrors,
				busy: busy || null !== actionBusy,
				save: () => submitStep( { advance: false, force: true } ),
				next: goNext,
				back: () => goTo( index - 1 ),
				// The type says «only when step.skippable»: enforce it here, not in every component.
				skip: () => {
					if ( false !== step.skippable ) {
						skipStep();
					}
				},
				runAction: ( actionId ) =>
					new Promise( ( resolve ) => {
						const action = ( step.actions || [] ).find( ( a ) => a.id === actionId );
						if ( ! action ) {
							resolve( { status: 'error', message: __( 'Неизвестное действие.', 'woodev-plugin-framework' ), data: {} } );
							return;
						}
						if ( action.destructive ) {
							// Settled by the confirmation panel (yes → the answer, no → 'cancelled').
							settleComponentAction( { status: 'cancelled', message: '', data: {} } );
							componentResolveRef.current = resolve;
							runStepAction( action );
							return;
						}
						runStepAction( action ).then( resolve );
					} ),
			} )
		);
	}

	/**
	 * The step's edits changed (the same handler for built-in fields and custom components).
	 *
	 * @param {Object} v field id => value for the current step.
	 */
	function onStepChange( v ) {
		if ( Object.keys( fieldErrors ).length > 0 ) {
			setFieldErrors( {} );
		}
		setValues( { ...values, [ step.id ]: v } );
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
			? renderFinishStep()
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
						conditionValues: collectConditionValues(),
													onChange: onStepChange,
						showErrors,
						serverErrors: fieldErrors,
						body: step.component ? renderComponentBody() : null,
					} ),
					renderStepActions(),
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
										disabled: busy || null !== actionBusy,
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
									disabled: busy || null !== actionBusy,
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
					'aria-disabled': exiting ? 'true' : undefined,
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
