/**
 * Setup Wizard — root component.
 *
 * A thin state machine over the PHP-declared steps: render the current step,
 * save its values to REST on "Continue" (advance only on success), allow Back,
 * finalize on the last step, and Skip at any time. All step data and copy come
 * from window.woodevSetupWizard (PHP-driven). Classic JSX runtime: createElement
 * / Fragment imported and used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import StepView from './step-view';
import Progress from './progress';
import { saveStep, complete } from './rest';

/**
 * Wizard root.
 *
 * @return {Object} React element.
 */
export default function App() {
	const { steps, finishActions, pluginName } = window.woodevSetupWizard;
	const [ index, setIndex ] = useState( 0 );
	const [ values, setValues ] = useState( {} );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ done, setDone ] = useState( false );

	const step = steps[ index ];
	const isLast = index === steps.length - 1;

	async function goNext() {
		setError( null );
		setBusy( true );
		try {
			if ( 'settings' === step.type ) {
				await saveStep( step.id, values[ step.id ] || {} );
			}
			if ( isLast ) {
				await complete( 'completed' );
				setDone( true );
			} else {
				setIndex( index + 1 );
			}
		} catch ( e ) {
			setError( e.message || __wizardError() );
		} finally {
			setBusy( false );
		}
	}

	async function skip() {
		setError( null );
		setBusy( true );
		try {
			await complete( 'skipped' );
			setDone( true );
		} catch ( e ) {
			setError( e.message || __wizardError() );
		} finally {
			setBusy( false );
		}
	}

	if ( done ) {
		return createElement(
			'div',
			{ className: 'woodev-setup-finish' },
			createElement( 'h1', null, `${ pluginName }` ),
			( finishActions || [] ).map( ( action, i ) =>
				createElement(
					Button,
					{ key: i, variant: 'primary', href: action.url },
					action.label
				)
			)
		);
	}

	return createElement(
		Fragment,
		null,
		createElement( Progress, { steps, index } ),
		error &&
			createElement( Notice, { status: 'error', isDismissible: false }, error ),
		createElement( StepView, {
			step,
			values: values[ step.id ] || {},
			onChange: ( v ) => setValues( { ...values, [ step.id ]: v } ),
		} ),
		createElement(
			'div',
			{ className: 'woodev-setup-actions' },
			index > 0 &&
				createElement(
					Button,
					{ variant: 'secondary', disabled: busy, onClick: () => setIndex( index - 1 ) },
					'Назад'
				),
			createElement(
				Button,
				{ variant: 'primary', isBusy: busy, disabled: busy, onClick: goNext },
				isLast ? 'Завершить' : 'Продолжить'
			),
			! isLast &&
				createElement(
					Button,
					{ variant: 'link', disabled: busy, onClick: skip },
					'Пропустить'
				)
		)
	);
}

/**
 * Default error copy.
 *
 * @return {string} message.
 */
function __wizardError() {
	return 'Ошибка сохранения. Попробуйте ещё раз.';
}
