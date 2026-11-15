/**
 * The three prop-driven pieces of a table card's chrome: the bulk-action select, the counted view row and the period
 * picker. They hold no words and no state of their own; these tests hand them both.
 *
 * @see src/components/bulk-action-select.tsx
 * @see src/components/count-links.tsx
 * @see src/components/period-picker/period-picker.tsx
 */

import '@testing-library/jest-dom';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { BulkActionSelect, type BulkActionSelectProps } from '../../src/components/bulk-action-select';
import { CountLinks, withAllEntry } from '../../src/components/count-links';
import { PeriodPicker, periodButtonLabel, type PeriodLabels } from '../../src/components/period-picker/period-picker';

const ACTIONS = [
	{ value: 'send', label: 'Отправить' },
	{ value: 'archive', label: 'Архивировать' },
];

function bulk( overrides: Partial< BulkActionSelectProps > = {} ) {
	const props: BulkActionSelectProps = {
		actions: ACTIONS,
		value: '',
		onChange: jest.fn(),
		onApply: jest.fn(),
		labels: { placeholder: 'Выберите действие', apply: 'Применить', label: 'Массовые действия' },
		...overrides,
	};

	render( <BulkActionSelect { ...props } /> );

	return props;
}

describe( 'BulkActionSelect', () => {
	test( 'offers the instruction first and every action after it; «Применить» waits for a choice', () => {
		bulk();

		const options = screen.getAllByRole( 'option' ).map( ( option ) => option.textContent );

		expect( options ).toEqual( [ 'Выберите действие', 'Отправить', 'Архивировать' ] );
		expect( screen.getByRole( 'button', { name: 'Применить' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'a choice is handed to the caller and «Применить» runs it', () => {
		const props = bulk( { value: 'send' } );

		fireEvent.change( screen.getByRole( 'combobox', { name: 'Массовые действия' } ), { target: { value: 'archive' } } );
		expect( props.onChange ).toHaveBeenCalledWith( 'archive' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Применить' } ) );
		expect( props.onApply ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'with nothing selected «Применить» is off even for a chosen action', () => {
		const props = bulk( { value: 'send', disabled: true } );
		const apply = screen.getByRole( 'button', { name: 'Применить' } );

		expect( apply ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( apply );
		expect( props.onApply ).not.toHaveBeenCalled();
	} );

	test( 'a blocked action keeps the button off, and the reason is written beside it and described to the button', () => {
		const props = bulk( { value: 'send', disabledReason: 'По аккаунту идёт фоновая работа' } );
		const apply = screen.getByRole( 'button', { name: 'Применить' } );

		expect( screen.getByText( 'По аккаунту идёт фоновая работа' ) ).toBeInTheDocument();
		expect( apply ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( apply ).toHaveAccessibleDescription( 'По аккаунту идёт фоновая работа' );
		fireEvent.click( apply );
		expect( props.onApply ).not.toHaveBeenCalled();
	} );

	test( 'a free choice with a selection enables «Применить» and says nothing', () => {
		bulk( { value: 'send' } );

		expect( screen.getByRole( 'button', { name: 'Применить' } ) ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.queryByText( 'По аккаунту идёт фоновая работа' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'CountLinks', () => {
	const PARTS = [
		{ value: 'new', label: 'Новые', count: 28 },
		{ value: 'sent', label: 'Отправлены', count: 4 },
		{ value: 'lost', label: 'Потеряны', count: 0 },
	];

	test( '«Все» counts the sum of the entries and writes the empty value', () => {
		const onSelect = jest.fn();
		render( <CountLinks entries={ withAllEntry( PARTS, 'Все' ) } current="new" onSelect={ onSelect } /> );

		expect( screen.getByRole( 'link', { name: 'Все (32)' } ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'link', { name: 'Все (32)' } ) );
		expect( onSelect ).toHaveBeenCalledWith( '' );
	} );

	test( 'a known total replaces the sum (a row can sit in two entries)', () => {
		render( <CountLinks entries={ withAllEntry( PARTS, 'Все', 30 ) } current="" onSelect={ jest.fn() } /> );

		expect( screen.getByRole( 'link', { name: 'Все (30)' } ) ).toBeInTheDocument();
	} );

	test( 'a count of zero is hidden, unless it is the current entry', () => {
		const { rerender } = render(
			<CountLinks entries={ withAllEntry( PARTS, 'Все' ) } current="" onSelect={ jest.fn() } />
		);

		expect( screen.queryByRole( 'link', { name: /Потеряны/ } ) ).not.toBeInTheDocument();

		rerender( <CountLinks entries={ withAllEntry( PARTS, 'Все' ) } current="lost" onSelect={ jest.fn() } /> );
		expect( screen.getByRole( 'link', { name: 'Потеряны (0)' } ) ).toHaveAttribute( 'aria-current', 'page' );
	} );

	test( 'the current entry is marked and selecting it again changes nothing', () => {
		const onSelect = jest.fn();
		render( <CountLinks entries={ withAllEntry( PARTS, 'Все' ) } current="new" onSelect={ onSelect } /> );

		const current = screen.getByRole( 'link', { name: 'Новые (28)' } );
		expect( current ).toHaveAttribute( 'aria-current', 'page' );
		expect( screen.getByRole( 'link', { name: 'Отправлены (4)' } ) ).not.toHaveAttribute( 'aria-current' );

		fireEvent.click( current );
		expect( onSelect ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'link', { name: 'Отправлены (4)' } ) );
		expect( onSelect ).toHaveBeenCalledWith( 'sent' );
	} );
} );

const LABELS: PeriodLabels = {
	label: 'Период',
	legend: 'Выберите период',
	all: 'Всё время',
	presets: {
		today: 'Сегодня',
		yesterday: 'Вчера',
		week: 'С начала недели',
		last_week: 'Прошлая неделя',
		month: 'С начала месяца',
		last_month: 'Прошлый месяц',
		quarter: 'С начала квартала',
		last_quarter: 'Прошлый квартал',
		year: 'С начала года',
		last_year: 'Прошлый год',
	},
	custom: 'Произвольный период',
	back: 'Назад',
	apply: 'Применить период',
	from: 'Период: С',
	to: 'Период: По',
};

const SUNDAY = new Date( 2026, 10, 15 );

describe( 'PeriodPicker', () => {
	async function open( value: { from?: string; to?: string } = {} ) {
		const onChange = jest.fn();
		render( <PeriodPicker value={ value } onChange={ onChange } labels={ LABELS } today={ SUNDAY } /> );

		fireEvent.click( screen.getByRole( 'button', { name: `Период: ${ periodButtonLabel( value, LABELS, SUNDAY ) }` } ) );
		// The popover places itself once it is open; let that happen inside the test.
		await act( async () => {} );

		return onChange;
	}

	test( 'the button says «all time» for no bound, the preset while the dates still are it, the dates otherwise', () => {
		expect( periodButtonLabel( {}, LABELS, SUNDAY ) ).toBe( 'Всё время' );
		expect( periodButtonLabel( { from: '2026-11-09', to: '2026-11-15' }, LABELS, SUNDAY ) ).toBe( 'С начала недели' );
		expect( periodButtonLabel( { from: '2026-11-01', to: '2026-11-09' }, LABELS, SUNDAY ) ).toBe( '01.11.2026 — 09.11.2026' );
		expect( periodButtonLabel( { from: '2026-11-01' }, LABELS, SUNDAY ) ).toBe( '01.11.2026 — …' );
	} );

	test( 'the button is named with the label above it, so a screen reader says what the value is for', () => {
		render( <PeriodPicker value={ {} } onChange={ jest.fn() } labels={ LABELS } today={ SUNDAY } /> );

		expect( screen.getByRole( 'button', { name: 'Период: Всё время' } ) ).toBeInTheDocument();
	} );

	test( 'lists «all time», the ten periods and the custom range', async () => {
		await open();

		expect( screen.getAllByRole( 'radio' ) ).toHaveLength( 11 );
		expect( screen.getByRole( 'button', { name: 'Произвольный период' } ) ).toBeInTheDocument();
	} );

	test( 'a preset writes its days, counted from today', async () => {
		const onChange = await open();

		fireEvent.click( screen.getByRole( 'radio', { name: 'Прошлая неделя' } ) );

		expect( onChange ).toHaveBeenCalledWith( { from: '2026-11-02', to: '2026-11-08' } );
	} );

	test( '«Всё время» clears the range', async () => {
		const onChange = await open( { from: '2026-11-09', to: '2026-11-15' } );

		fireEvent.click( screen.getByRole( 'radio', { name: 'Всё время' } ) );

		expect( onChange ).toHaveBeenCalledWith( {} );
	} );

	test( 'without the WooCommerce calendar the custom range is two native date inputs', async () => {
		const onChange = await open();

		fireEvent.click( screen.getByRole( 'button', { name: 'Произвольный период' } ) );
		const apply = screen.getByRole( 'button', { name: 'Применить период' } );
		expect( apply ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Период: С' ), { target: { value: '2026-11-01' } } );
		fireEvent.change( screen.getByLabelText( 'Период: По' ), { target: { value: '2026-11-09' } } );
		fireEvent.click( apply );

		expect( onChange ).toHaveBeenCalledWith( { from: '2026-11-01', to: '2026-11-09' } );
	} );
} );
