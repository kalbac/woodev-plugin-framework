/**
 * «The articles of new cards»: a card of its own under the mapping form, for a marketplace whose adapter can say which article a
 * product would be sent with. When it appears it makes one bounded read of the saved mapping; the rest of the shop is read only
 * on «show more».
 *
 * @package woodev-plugin-framework
 */

import { Fragment, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { errorLines, type MappingsApi } from './api';
import { FormCard } from './form-parts';
import { Notices } from './notices';
import type { ArticleProduct, ArticlesCursor, Messages } from './types';

interface Props {
	api: MappingsApi;
	messages: Messages;
	mappingId: number;
}

const START: ArticlesCursor = { offset: 0, skip: 0 };

/** Adds a read to what is on the screen; a product whose variations did not fit one read continues in the next one. */
function appended( shown: ArticleProduct[], read: ArticleProduct[] ): ArticleProduct[] {
	const last = shown[ shown.length - 1 ];
	const [ first, ...rest ] = read;

	if ( ! last || ! first || first.id !== last.id ) {
		return [ ...shown, ...read ];
	}

	const lines = [ ...last.articles ];

	first.articles.forEach( ( line ) => {
		const at = lines.findIndex( ( known ) => known.article === line.article && known.note === line.note );

		if ( at === -1 ) {
			lines.push( line );
		} else {
			lines[ at ] = { ...line, variations: [ lines[ at ].variations, line.variations ].filter( Boolean ).join( '; ' ) };
		}
	} );

	return [ ...shown.slice( 0, -1 ), { ...last, articles: lines }, ...rest ];
}

export function ArticlePreview( { api, messages, mappingId }: Props ) {
	const [ products, setProducts ] = useState< ArticleProduct[] >( [] );
	/** Where the next read starts; null once every product has been read. */
	const [ next, setNext ] = useState< ArticlesCursor | null >( START );
	const [ loading, setLoading ] = useState( true );
	const [ failure, setFailure ] = useState< string[] | null >( null );
	/** Counts the mounts; an answer of an earlier one is ignored. */
	const run = useRef( 0 );

	const load = useCallback(
		async ( from: ArticlesCursor ) => {
			const current = run.current;

			setLoading( true );
			setFailure( null );

			try {
				const step = await api.articles( mappingId, from );

				if ( current !== run.current ) {
					return;
				}

				setProducts( ( shown ) => appended( shown, step.products ) );
				setNext( step.next );
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
		setNext( START );
		load( START );

		return () => {
			run.current += 1;
		};
	}, [ load ] );

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
			{ /* Nothing is said about the products before a read has answered: `next` is still the start. */ }
			{ ! loading && next !== START && products.length === 0 && (
				<p role="status">{ next === null ? messages.articlesEmpty : messages.articlesNoneYet }</p>
			) }
			{ loading && (
				<p role="status">
					<Spinner />
					{ messages.articlesLoading }
				</p>
			) }
			<Notices notice={ failure ? { status: 'error', lines: failure } : null } onDismiss={ () => setFailure( null ) } />
			{ ! loading && next !== null && (
				<Button __next40pxDefaultSize variant="secondary" onClick={ () => load( next ) }>
					{ failure || next === START ? messages.retry : messages.articlesMore }
				</Button>
			) }
		</FormCard>
	);
}
