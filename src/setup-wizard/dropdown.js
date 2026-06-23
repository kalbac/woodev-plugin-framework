/**
 * Setup Wizard — custom dropdown control.
 *
 * A controlled dropdown styled to match the approved mockup (`.dropdown*`):
 * a trigger showing the selected label + a chevron, toggling a floating menu of
 * options; the selected option is accent-tinted and carries a check tick.
 *
 * Built on `@wordpress/components` `Dropdown` (renderToggle/renderContent) so the
 * popover is keyboard-accessible and positioned for us; the visual chrome is our
 * own BEM markup styled in style.scss. Classic JSX runtime: createElement used
 * directly (no JSX).
 *
 * @package woodev-plugin-framework
 */

import { createElement, Fragment, useRef } from '@wordpress/element';
import { Dropdown } from '@wordpress/components';
import { ChevronIcon, CheckFilledIcon } from './icons';

/**
 * Controlled dropdown.
 *
 * @param {Object}   props          component props.
 * @param {string}   props.value    currently selected option value.
 * @param {Array}    props.options  [{ value, label }] options.
 * @param {Function} props.onChange change handler invoked with the new value.
 * @return {Object} React element.
 */
export default function WizardDropdown( { value, options = [], onChange } ) {
	const selected = options.find( ( option ) => String( option.value ) === String( value ) );
	const selectedLabel = selected ? selected.label : '';
	const triggerRef = useRef( null );

	return createElement( Dropdown, {
		className: 'woodev-setup__dropdown',
		contentClassName: 'woodev-setup__dropdown-popover',
		popoverProps: { placement: 'bottom-start', offset: 6 },
		renderToggle: ( { isOpen, onToggle } ) =>
			createElement(
				'button',
				{
					type: 'button',
					ref: triggerRef,
					className:
						'woodev-setup__dropdown-trigger' +
						( isOpen ? ' is-open' : '' ),
					onClick: onToggle,
					'aria-expanded': isOpen,
					'aria-haspopup': 'listbox',
				},
				createElement( 'span', { className: 'woodev-setup__dropdown-value' }, selectedLabel ),
				createElement(
					'span',
					{ className: 'woodev-setup__dropdown-chevron' },
					createElement( ChevronIcon )
				)
			),
		renderContent: ( { onClose } ) =>
			createElement(
				'div',
				{
					className: 'woodev-setup__dropdown-menu',
					role: 'listbox',
					// Match the menu width to the full-width trigger.
					style: { width: triggerRef.current ? triggerRef.current.offsetWidth + 'px' : undefined },
				},
				options.map( ( option ) => {
					const isSelected = String( option.value ) === String( value );

					return createElement(
						'button',
						{
							key: String( option.value ),
							type: 'button',
							role: 'option',
							'aria-selected': isSelected,
							className:
								'woodev-setup__dropdown-item' +
								( isSelected ? ' is-selected' : '' ),
							onClick: () => {
								onChange( option.value );
								onClose();
							},
						},
						createElement( 'span', null, option.label ),
						isSelected &&
							createElement(
								Fragment,
								null,
								createElement(
									'span',
									{ className: 'woodev-setup__dropdown-tick' },
									createElement( CheckFilledIcon )
								)
							)
					);
				} )
			),
	} );
}
