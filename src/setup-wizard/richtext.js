/**
 * Setup Wizard — minimal functional rich-text editor.
 *
 * A controlled rich-text control built on a `contentEditable` div plus a small
 * toolbar (bold / italic / unordered list / link) wired to `document.execCommand`.
 * The editable div is seeded from `value` on first render (uncontrolled innerHTML)
 * to avoid caret jumps; subsequent edits flow OUT via `onChange( innerHTML )` and
 * are never re-applied from props. Toolbar buttons preventDefault on mousedown so
 * focus stays in the editor, then run the command and emit the new innerHTML.
 *
 * Classic JSX runtime: createElement used directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Minimal contentEditable rich-text control.
 *
 * @param {Object}   props          component props.
 * @param {string}   props.value    current HTML value.
 * @param {Function} props.onChange change handler invoked with the new HTML.
 * @return {Object} React element.
 */
export default function WizardRichText( { value = '', onChange } ) {
	const editorRef = useRef( null );

	// Seed the editable node from `value` ONCE on mount. We never feed `value`
	// back into the DOM on re-render — React reconciling a contentEditable's
	// children resets the caret to the start on every keystroke (the bug we hit).
	useEffect( () => {
		if ( editorRef.current ) {
			editorRef.current.innerHTML = value || '';
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	/**
	 * Emits the editor's current innerHTML to the parent.
	 */
	function emit() {
		if ( editorRef.current && onChange ) {
			onChange( editorRef.current.innerHTML );
		}
	}

	/**
	 * Runs an execCommand, keeps focus in the editor, then emits the new value.
	 *
	 * @param {string} command execCommand identifier.
	 * @param {string} arg     optional command argument.
	 */
	function run( command, arg ) {
		if ( editorRef.current ) {
			editorRef.current.focus();
		}
		document.execCommand( command, false, arg );
		emit();
	}

	/**
	 * Toolbar button factory.
	 *
	 * @param {Object}   opts          button options.
	 * @param {string}   opts.label    visible glyph.
	 * @param {string}   opts.title    accessible title.
	 * @param {string}   [opts.cls]    extra class (is-bold / is-italic).
	 * @param {Function} opts.onAction click handler running the command.
	 * @return {Object} React element.
	 */
	function toolButton( { label, title, cls, onAction } ) {
		return createElement(
			'button',
			{
				type: 'button',
				className: cls || undefined,
				title,
				'aria-label': title,
				// Keep focus/selection in the editor when clicking the toolbar.
				onMouseDown: ( e ) => e.preventDefault(),
				onClick: onAction,
			},
			label
		);
	}

	return createElement(
		'div',
		{ className: 'woodev-setup__richtext' },
		createElement(
			'div',
			{ className: 'woodev-setup__richtext-toolbar' },
			toolButton( {
				label: 'B',
				title: __( 'Полужирный', 'woodev-plugin-framework' ),
				cls: 'is-bold',
				onAction: () => run( 'bold' ),
			} ),
			toolButton( {
				label: 'I',
				title: __( 'Курсив', 'woodev-plugin-framework' ),
				cls: 'is-italic',
				onAction: () => run( 'italic' ),
			} ),
			toolButton( {
				label: '☰',
				title: __( 'Список', 'woodev-plugin-framework' ),
				onAction: () => run( 'insertUnorderedList' ),
			} ),
			toolButton( {
				label: '🔗',
				title: __( 'Ссылка', 'woodev-plugin-framework' ),
				onAction: () => {
					const url = window.prompt( __( 'Адрес ссылки (URL):', 'woodev-plugin-framework' ), 'https://' );
					if ( url ) {
						run( 'createLink', url );
					}
				},
			} )
		),
		createElement( 'div', {
			ref: editorRef,
			className: 'woodev-setup__richtext-editor',
			contentEditable: true,
			suppressContentEditableWarning: true,
			role: 'textbox',
			'aria-multiline': 'true',
			onInput: emit,
			// Content is seeded imperatively once on mount (see useEffect above) and
			// never re-applied from props — re-applying resets the caret on typing.
		} )
	);
}
