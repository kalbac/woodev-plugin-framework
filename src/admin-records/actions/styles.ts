/**
 * Inline styles of the action dialog, for the same reason as the shell's: the library brings no stylesheet.
 *
 * @package woodev-plugin-framework
 */

import type { CSSProperties } from 'react';

export const STYLES = {
	bulk: { display: 'flex', flexWrap: 'wrap', gap: '8px', alignItems: 'center', margin: '0 0 12px' },
	scope: { margin: '0 0 16px', padding: '8px 12px', background: '#f6f7f7', borderLeft: '4px solid #2271b1' },
	form: { display: 'flex', flexDirection: 'column', gap: '16px', margin: '0 0 16px' },
	fieldset: { border: 0, margin: 0, padding: 0 },
	counts: { display: 'flex', flexWrap: 'wrap', gap: '24px', margin: '0 0 16px' },
	count: { display: 'flex', flexDirection: 'column' },
	countValue: { margin: 0, fontSize: '20px', fontWeight: 600 },
	groups: { margin: '0 0 16px', paddingLeft: '20px' },
	group: { margin: '0 0 8px' },
	groupHeading: { fontWeight: 600 },
	examples: { color: '#50575e' },
	footer: { display: 'flex', flexWrap: 'wrap', gap: '8px', justifyContent: 'flex-end', marginTop: '24px' },
	busy: { display: 'flex', gap: '8px', alignItems: 'center', margin: '0 0 16px' },
} satisfies Record< string, CSSProperties >;
