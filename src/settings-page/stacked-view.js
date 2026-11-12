/**
 * A tab whose sections are drawn one after another, each under its own heading, instead of as sub-tabs.
 *
 * Sections flagged `stacked` (`Settings_Section::stacked()`) belong to tabs that list one block per account: «Параметры»
 * (an account's settings under its name) and «Уведомления» (the switches, then a row per account). The sections keep their
 * own card, fields and Save button, so saving works as it does on any other tab.
 *
 * @package woodev-plugin-framework
 */

/**
 * Whether a tab draws its sections stacked.
 *
 * @param {Object} tab the tab schema.
 * @return {boolean} true when some section is flagged `stacked`.
 */
export function isStackedTab( tab ) {
	return ( tab.sections || [] ).some( ( section ) => !! section.stacked );
}

/**
 * @param {Object}   props
 * @param {Object}   props.tab           the stacked tab.
 * @param {Function} props.renderSection (tab, sectionId) => node — the card of one section.
 */
export default function StackedView( { tab, renderSection } ) {
	return (
		<div className="woodev-stacked">
			{ ( tab.sections || [] ).map( ( section ) => (
				<section key={ section.id } className="woodev-stacked__section">
					<h3 className="woodev-stacked__title">
						{ section.label }
						{ section.subtitle && <span className="woodev-stacked__subtitle">{ section.subtitle }</span> }
					</h3>
					{ renderSection( tab, section.id ) }
				</section>
			) ) }
		</div>
	);
}
