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
	test( 'keeps a short state on one line', () => {
		render( <div>{ renderCell( 'badge', { label: 'Создан', tone: 'success' } ) }</div> );

		expect( screen.getByText( 'Создан' ) ).toHaveStyle( { whiteSpace: 'nowrap' } );
	} );

	test( 'wraps a sentence inside a bounded width', () => {
		render(
			<div>
				{ renderCell( 'badge', { label: 'Не заполнено обязательное свойство «Бренд» в категории', tone: 'danger' } ) }
			</div>
		);

		expect( screen.getByText( /Не заполнено обязательное свойство/ ) ).toHaveStyle( {
			whiteSpace: 'normal',
			maxWidth: '240px',
		} );
	} );
} );
