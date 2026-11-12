/**
 * The «Диагностика» page's decisions: the switch label and the copy outcome, from the server's copy.
 *
 * @see src/marketplace-admin/views/diagnostics/report.ts
 */

import { copyOutcome, loggingSwitchLabel } from '../../src/marketplace-admin/views/diagnostics/report';

const messages = {
	enableLogging: 'Включить логирование',
	disableLogging: 'Выключить логирование',
	copied: 'Скопировано',
	copyFailed: 'Не удалось скопировать: выделите текст ниже и скопируйте вручную.',
};

describe( 'loggingSwitchLabel', () => {
	it( 'offers the opposite of the current state', () => {
		expect( loggingSwitchLabel( false, messages ) ).toBe( 'Включить логирование' );
		expect( loggingSwitchLabel( true, messages ) ).toBe( 'Выключить логирование' );
	} );
} );

describe( 'copyOutcome', () => {
	it( 'says whether the clipboard accepted the report text', () => {
		expect( copyOutcome( true, messages ) ).toBe( 'Скопировано' );
		expect( copyOutcome( false, messages ) ).toBe( 'Не удалось скопировать: выделите текст ниже и скопируйте вручную.' );
	} );
} );
