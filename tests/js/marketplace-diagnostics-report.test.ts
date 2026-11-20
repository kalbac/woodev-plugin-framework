/**
 * The «Диагностика» page's decisions: the copy outcome, from the server's copy.
 *
 * @see src/marketplace-admin/views/diagnostics/report.ts
 */

import { copyOutcome } from '../../src/marketplace-admin/views/diagnostics/report';

const messages = {
	copied: 'Скопировано',
	copyFailed: 'Не удалось скопировать: выделите текст ниже и скопируйте вручную.',
};

describe( 'copyOutcome', () => {
	it( 'says whether the clipboard accepted the report text', () => {
		expect( copyOutcome( true, messages ) ).toBe( 'Скопировано' );
		expect( copyOutcome( false, messages ) ).toBe( 'Не удалось скопировать: выделите текст ниже и скопируйте вручную.' );
	} );
} );
