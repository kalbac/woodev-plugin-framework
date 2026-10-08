/**
 * Reference custom setup-wizard step component (author example, #109 D4).
 *
 * Plain script, no build step: it publishes a component under the handle the PHP step declares
 * (`Step::set_component( 'woodev-test-wizard-review', 'ReviewStep' )`). The framework renders it
 * inside the standard step frame and passes the props documented in
 * `src/setup-wizard/types.ts` (values, onChange, save, next, runAction, errors, busy…).
 */
( function ( wp, window ) {
	var el = wp.element.createElement;
	var useState = wp.element.useState;

	function ReviewStep( props ) {
		var state = useState( '' );
		var message = state[ 0 ];
		var setMessage = state[ 1 ];

		function ping() {
			props.runAction( 'ping' ).then( function ( answer ) {
				setMessage( answer.message );
			} );
		}

		return el(
			'div',
			{ className: 'woodev-test-wizard-review' },
			el( 'p', null, 'Это шаг, который рисует плагин. Кнопка ниже вызывает действие на сервере.' ),
			el(
				wp.components.Button,
				{ variant: 'secondary', onClick: ping, disabled: props.busy },
				'Проверить связь'
			),
			message ? el( 'p', { role: 'status' }, message ) : null
		);
	}

	window.woodevSetupWizardComponents = window.woodevSetupWizardComponents || {};
	window.woodevSetupWizardComponents[ 'woodev-test-wizard-review' ] = { ReviewStep: ReviewStep };
}( window.wp, window ) );
