/**
 * Inline styles of the activity views (the library brings no stylesheet of its own; see `../styles`).
 *
 * @package woodev-plugin-framework
 */

import type { CSSProperties } from 'react';

export const ACTIVITY_STYLES = {
	account: { margin: '0 0 12px', padding: '8px 12px', background: '#f0f6fc', borderLeft: '4px solid #72aee6' },
	notice: { margin: '0 0 12px', padding: '8px 12px', background: '#fcf9e8', borderLeft: '4px solid #dba617' },
	row: { display: 'flex', flexDirection: 'column', gap: '4px', flexBasis: '100%', marginTop: '4px' },
	lock: { color: '#50575e' },
	processes: { listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '4px' },
	process: { display: 'flex', flexDirection: 'column', gap: '2px' },
	meter: { width: '160px', height: '8px' },
} satisfies Record< string, CSSProperties >;
