/**
 * «The articles of new cards»: a card of its own under the mapping form, for a marketplace whose adapter can say which article a
 * product would be sent with. It reads the saved mapping when it appears; «show more» reads the next products.
 *
 * @package woodev-plugin-framework
 */

import { Fragment, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { errorLines, type MappingsApi } from './api';
import { FormCard } from './form-parts';
import { Notices } from './notices';
import type { ArticleProduct, Messages } from './types';

interface Props {
	api: MappingsApi;
	messages: Messages;
	mappingId: number;
}

export function ArticlePreview( { api, messages, mappingId }: Props ) {
	const [ products, setProducts ] = useState< ArticleProduct[] >( [] );
	/** Where the next read starts; null once every product has been read. */
	const [ offset, setOffset ] = useState< number | null >( 0 );
	const [ loading, setLoading ] = useState( true );
	const [ failure, setFailure ] = useState< string[] | null >( null );
	/** Counts the mounts; an answer of an earlier one is ignored. */
	const run = useRef( 0 );

	/** Reads on from `start` until some products are found or none are left: a stretch of products with cards answers nothing. */
	const load = useCallback(
		async ( start: number ) => {
			const current = run.current;

			setLoading( true );
			setFailure( null );

			try {
				let next = start;

				for ( ;; ) {
					const step = await api.articles( mappingId, next );

					if ( current !== run.current ) {
						return;
					}

					setProducts( ( previous ) => [ ...previous, ...step.products ] );
					setOffset( step.next_offset || null );
					next = step.next_offset;

					if ( step.products.length > 0 || next === 0 ) {
						break;
					}
				}
			} catch ( error ) {
				if ( current !== run.current ) {
					return;
				}

				setFailure( errorLines( error, messages.requestFailed ) );
			}

			setLoading( false );
		},
		[ api, mappingId, messages.requestFailed ]
	);

	useEffect( () => {
		setProducts( [] );
		setOffset( 0 );
		load( 0 );

		return () => {
			run.current += 1;
		};
	}, [ load ] );

	// A dash with a note of its own is already explained.
	const missing = products.some( ( product ) => product.articles.some( ( line ) => line.article === '' && line.note === '' ) );
	const variable = products.some( ( product ) => product.articles.some( ( line ) => line.variations !== '' ) );

	return (
		<FormCard title={ messages.articlesHeading } className="woodev-mapping-articles">
			<p className="woodev-group__desc">{ messages.articlesHelp }</p>

			{ products.length > 0 && (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ messages.columnProduct }</th>
							{ variable && <th>{ messages.columnVariations }</th> }
							<th>{ messages.columnArticle }</th>
						</tr>
					</thead>
					<tbody>
						{ products.map( ( product ) => (
							<Fragment key={ product.id }>
								{ product.articles.map( ( line, index ) => (
									<tr key={ `${ line.article }\n${ line.note }` }>
										{ index === 0 && (
											<td rowSpan={ product.articles.length }>
												{ product.url ? <a href={ product.url }>{ product.name }</a> : product.name }
											</td>
										) }
										{ variable && <td>{ line.variations }</td> }
										<td>
											{ line.article === '' ? '—' : <code>{ line.article }</code> }
											{ line.note !== '' && <p className="description">{ line.note }</p> }
										</td>
									</tr>
								) ) }
							</Fragment>
						) ) }
					</tbody>
				</table>
			) }

			{ missing && <p className="woodev-group__desc">{ messages.articlesMissing }</p> }
			{ ! loading && offset === null && products.length === 0 && <p role="status">{ messages.articlesEmpty }</p> }
			{ loading && (
				<p role="status">
					<Spinner />
					{ messages.articlesLoading }
				</p>
			) }
			<Notices notice={ failure ? { status: 'error', lines: failure } : null } onDismiss={ () => setFailure( null ) } />
			{ ! loading && offset !== null && (
				<Button __next40pxDefaultSize variant="secondary" onClick={ () => load( offset ) }>
					{ failure || products.length === 0 ? messages.retry : messages.articlesMore }
				</Button>
			) }
		</FormCard>
	);
}
