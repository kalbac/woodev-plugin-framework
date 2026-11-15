/**
 * The counted row above a table — «Все (120) | Не связан (80) | Продаётся (37)» — in WordPress's own list-table shape:
 * `ul > li > a`, the current entry marked with `aria-current`, the separator drawn in CSS so a screen reader hears links
 * and not a stray pipe.
 *
 * The numbers are the point of the control: the merchant checks them against the table. A count of zero is hidden —
 * except on the current entry, which would otherwise vanish from under the merchant — and the caller decides which
 * entry stands for «everything» ({@link withAllEntry}).
 *
 * @package woodev-plugin-framework
 */

export interface CountEntry {
	value: string;
	label: string;
	count: number;
}

export interface CountLinksProps {
	entries: CountEntry[];
	/** The value of the current entry. */
	current: string;
	onSelect: ( value: string ) => void;
	/** The row's accessible name. */
	label?: string;
}

/**
 * The entries with the one that clears the filter in front: its count is `total` when the caller knows it, else the sum
 * of the others.
 *
 * @param entries One entry per option.
 * @param label   The «all» label.
 * @param total   The real total, when it is known; the sum of the counts otherwise.
 */
export function withAllEntry( entries: CountEntry[], label: string, total?: number ): CountEntry[] {
	const sum = entries.reduce( ( carry, entry ) => carry + entry.count, 0 );

	return [ { value: '', label, count: total ?? sum }, ...entries ];
}

export function CountLinks( { entries, current, onSelect, label }: CountLinksProps ) {
	const shown = entries.filter( ( entry ) => entry.count > 0 || entry.value === current );

	return (
		<ul className="woodev-table-views" aria-label={ label || undefined }>
			{ shown.map( ( entry ) => {
				const isCurrent = entry.value === current;

				return (
					<li key={ entry.value } className="woodev-table-views__item">
						<a
							href="#"
							className={ 'woodev-table-views__link' + ( isCurrent ? ' current' : '' ) }
							aria-current={ isCurrent ? 'page' : undefined }
							onClick={ ( event ) => {
								event.preventDefault();
								if ( ! isCurrent ) {
									onSelect( entry.value );
								}
							} }
						>
							{ entry.label } <span className="woodev-table-views__count">({ entry.count })</span>
						</a>
					</li>
				);
			} ) }
		</ul>
	);
}
