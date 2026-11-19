/**
 * Two small parts of the records table that the polish of the marketplace screens leans on: the «Выбрано: N» counter keeps its
 * place while nothing is ticked, and a badge that holds a sentence (an error text) wraps instead of widening its column.
 *
 * @see src/components/bulk-action-select.tsx
 * @see src/admin-records/cells.tsx
 */

import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import { BulkActionSelect, type BulkActionLabels } from '../../src/components/bulk-action-select';
import { renderCell } from '../../src/admin-records/cells';

const LABELS: BulkActionLabels = { placeholder: 'Выберите действие', apply: 'Применить', label: 'Действие' };

function bulk( selected: string | undefined ) {
	return render(
		<BulkActionSelect
			actions={ [ { value: 'export', label: 'Выгрузить' } ] }
			value=""
			onChange={ () => undefined }
			onApply={ () => undefined }
			labels={ { ...LABELS, selected } }
		/>
	);
}

describe( 'the «Выбрано» counter', () => {
	test( 'is drawn empty while nothing is ticked, so its place is kept', () => {
		bulk( '' );

		const status = screen.getByRole( 'status' );
		expect( status ).toHaveClass( 'woodev-table-bulk__selected' );
		expect( status ).toBeEmptyDOMElement();
	} );

	test( 'says how many rows are ticked', () => {
		bulk( 'Выбрано: 2' );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Выбрано: 2' );
	} );

	test( 'is not drawn at all when the table has no such sentence', () => {
		bulk( undefined );

		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'a badge', () => {
	test( 'is the delivery page badge: a class per tone, no inline colours', () => {
		render( <div>{ renderCell( 'badge', { label: 'Создан', tone: 'success' } ) }</div> );

		const badge = screen.getByText( 'Создан' );

		expect( badge ).toHaveClass( 'woodev-records__badge', 'is-success' );
		expect( badge ).not.toHaveClass( 'is-long' );
		expect( badge.getAttribute( 'style' ) ).toBeNull();
	} );

	test( 'a sentence is marked long, so the stylesheet wraps it inside a bounded width', () => {
		render(
			<div>
				{ renderCell( 'badge', { label: 'Не заполнено обязательное свойство «Бренд» в категории', tone: 'danger' } ) }
			</div>
		);

		expect( screen.getByText( /Не заполнено обязательное свойство/ ) ).toHaveClass( 'is-long' );
	} );

	test( 'a tooltip is its sentences on hover and on keyboard focus, the badge being focusable', () => {
		render(
			<div>
				{ renderCell( 'badge', { label: 'Создан', tone: 'success', tooltip: 'Синхронизируются: остатки\nОшибка: нет фото' } ) }
			</div>
		);

		const badge = screen.getByText( 'Создан' );

		expect( badge ).toHaveClass( 'has-tooltip' );
		expect( badge ).toHaveAttribute( 'tabindex', '0' );
	} );

	test( 'with no tooltip it is not a tab stop', () => {
		render( <div>{ renderCell( 'badge', { label: 'Создан', tone: 'success' } ) }</div> );

		expect( screen.getByText( 'Создан' ) ).not.toHaveAttribute( 'tabindex' );
	} );
} );

describe( 'a stack image', () => {
	test( 'is the card photo: a lazy picture with its description', () => {
		render( <div>{ renderCell( 'stack', [ { type: 'image', value: { url: 'https://example.com/a.jpg', alt: 'Кружка' } } ] ) }</div> );

		const picture = screen.getByRole( 'img', { name: 'Кружка' } );

		expect( picture ).toHaveAttribute( 'src', 'https://example.com/a.jpg' );
		expect( picture ).toHaveAttribute( 'loading', 'lazy' );
	} );
} );

describe( 'a link', () => {
	test( 'to another site opens in a new tab, one on this site does not', () => {
		render(
			<div>
				{ renderCell( 'link', { label: 'Карточка', url: 'https://seller.example.com/app/products/1/edit' } ) }
				{ renderCell( 'link', { label: 'Товар', url: '/wp-admin/post.php?post=1' } ) }
			</div>
		);

		expect( screen.getByText( 'Карточка' ) ).toHaveAttribute( 'target', '_blank' );
		expect( screen.getByText( 'Карточка' ) ).toHaveAttribute( 'rel', 'noopener noreferrer' );
		expect( screen.getByText( 'Товар' ) ).not.toHaveAttribute( 'target' );
	} );
} );

describe( 'a stack text line', () => {
	test( 'carries its full text as the title, for a column that clips it', () => {
		const sku = 'SKU-1234567890-1234567890-1234567890-1234567890-1234567890';

		render( <div>{ renderCell( 'stack', [ { type: 'text', value: sku } ] ) }</div> );

		expect( screen.getByText( sku ) ).toHaveAttribute( 'title', sku );
	} );
} );
