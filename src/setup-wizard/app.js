/**
 * Setup Wizard — root component.
 *
 * A thin state machine over the PHP-declared steps in a modern WooCommerce
 * onboarding layout: branded header → numbered stepper → centered card with the
 * current step → action row. Save the current step's values to REST on
 * "Continue" (advance only on success), allow Back, finalize on the last step,
 * and Skip at any time. All step data + copy come from window.woodevSetupWizard
 * (PHP-driven). Classic JSX runtime: createElement / Fragment used directly.
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, CardBody } from '@wordpress/components';
import Stepper from './stepper';
import StepView from './step-view';
import { CheckIcon } from './icons';
import { saveStep, complete } from './rest';

/**
 * Wizard root.
 *
 * @return {Object} React element.
 */
export default function App() {
	const { steps, finishActions, pluginName, headerLogoUrl } = window.woodevSetupWizard;
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
			setError( e.message || __( 'Что-то пошло не так. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
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
			setError( e.message || __( 'Что-то пошло не так. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
		} finally {
			setBusy( false );
		}
	}

	return createElement(
		'div',
		{ className: 'woodev-setup' },
		createElement(
			'header',
			{ className: 'woodev-setup__header' },
			headerLogoUrl
				? createElement( 'img', { className: 'woodev-setup__logo', src: headerLogoUrl, alt: pluginName } )
				: createElement( 'span', { className: 'woodev-setup__title' }, pluginName )
		),
		done
			? renderFinish( pluginName, finishActions )
			: createElement(
				Fragment,
				null,
				createElement( Stepper, { steps, index } ),
				createElement(
					Card,
					{ className: 'woodev-setup__card', size: 'large' },
					createElement(
						CardBody,
						null,
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
						} )
					)
				),
				createElement(
					'div',
					{ className: 'woodev-setup__actions' },
					createElement(
						'div',
						{ className: 'woodev-setup__actions-left' },
						index > 0 &&
							createElement(
								Button,
								{ variant: 'tertiary', disabled: busy, onClick: () => setIndex( index - 1 ) },
								__( 'Назад', 'woodev-plugin-framework' )
							)
					),
					createElement(
						'div',
						{ className: 'woodev-setup__actions-right' },
						! isLast &&
							createElement(
								Button,
								{ variant: 'link', disabled: busy, onClick: skip, className: 'woodev-setup__skip' },
								__( 'Пропустить', 'woodev-plugin-framework' )
							),
						createElement(
							Button,
							{ variant: 'primary', isBusy: busy, disabled: busy, onClick: goNext },
							isLast
								? __( 'Завершить', 'woodev-plugin-framework' )
								: __( 'Продолжить', 'woodev-plugin-framework' )
						)
					)
				)
			)
	);
}

/**
 * Renders the completion screen.
 *
 * @param {string} pluginName    plugin display name.
 * @param {Array}  finishActions "what's next" action descriptors.
 * @return {Object} React element.
 */
function renderFinish( pluginName, finishActions ) {
	return createElement(
		Card,
		{ className: 'woodev-setup__card woodev-setup__card--finish', size: 'large' },
		createElement(
			CardBody,
			null,
			createElement( 'div', { className: 'woodev-setup__finish-badge' }, createElement( CheckIcon ) ),
			createElement(
				'h1',
				{ className: 'woodev-setup__finish-title' },
				__( 'Готово!', 'woodev-plugin-framework' )
			),
			createElement(
				'p',
				{ className: 'woodev-setup__finish-text' },
				/* translators: %s plugin name */
				__( 'Настройка завершена.', 'woodev-plugin-framework' ) + ' ' + pluginName
			),
			( finishActions || [] ).length > 0 &&
				createElement(
					'div',
					{ className: 'woodev-setup__finish-actions' },
					finishActions.map( ( action, i ) =>
						createElement(
							Button,
							{ key: i, variant: 0 === i ? 'primary' : 'secondary', href: action.url },
							action.label
						)
					)
				)
		)
	);
}
