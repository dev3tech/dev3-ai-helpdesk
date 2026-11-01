import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, Badge, Button, Card, Columns, ErrorNotice, Icon, Inline, JobProgress, PageHeader, ProgressBar, ResponsiveTable, Stack, useToast } from '../core3/ui';
import { timeAgo, useBoot } from './util';

export default function Knowledge( { onUsage } ) {
	const boot = useBoot();
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ gaps, setGaps ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ gapError, setGapError ] = useState( null );
	const ai = !! ( boot.ai && boot.ai.enabled && boot.ai.key_set );

	const load = () => {
		setError( null );
		request( 'kb/articles' ).then( setRows ).catch( setError );
		request( 'kb/status' )
			.then( ( s ) => {
				setStatus( s );
				if ( s.job ) {
					setJob( s.job );
				}
			} )
			.catch( () => {} );
		request( 'kb/gaps' ).then( setGaps ).catch( () => {} );
	};
	useEffect( load, [] );

	const reindex = () => {
		setBusy( 'index' );
		request( 'kb/reindex', { method: 'POST' } )
			.then( ( res ) => {
				setStatus( res.status );
				if ( res.job ) {
					setJob( res.job );
				} else {
					toast.success( __( 'The search index is up to date.', 'dev3-ai-helpdesk' ) );
				}
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};
	const findGaps = () => {
		setBusy( 'gaps' );
		setGapError( null );
		request( 'kb/gaps', { method: 'POST' } )
			.then( ( g ) => {
				setGaps( g );
				onUsage();
			} )
			.catch( setGapError )
			.finally( () => setBusy( '' ) );
	};

	const columns = [
		{
			key: 'title',
			label: __( 'Article', 'dev3-ai-helpdesk' ),
			primary: true,
			sortable: true,
			render: ( r ) => (
				<span className="dev3hd-cell-main">
					<strong>{ r.title || __( '(no title)', 'dev3-ai-helpdesk' ) }</strong>
					<span className="dev3hd-muted">
						{ r.category || __( 'Uncategorised', 'dev3-ai-helpdesk' ) }
						{ r.source ? ` · ${ sprintf( /* translators: %d: ticket id. */ __( 'from ticket %d', 'dev3-ai-helpdesk' ), r.source ) }` : '' }
					</span>
				</span>
			),
		},
		{ key: 'status', label: __( 'Status', 'dev3-ai-helpdesk' ), render: ( r ) => <Badge tone={ r.status === 'publish' ? 'success' : 'neutral' }>{ r.status === 'publish' ? __( 'Published', 'dev3-ai-helpdesk' ) : __( 'Draft', 'dev3-ai-helpdesk' ) }</Badge> },
		{ key: 'views', label: __( 'Views', 'dev3-ai-helpdesk' ), sortable: true, align: 'end' },
		{ key: 'helpful', label: __( 'Helpful', 'dev3-ai-helpdesk' ), sortable: true, align: 'end', sortValue: ( r ) => ( r.helpful === null ? -1 : r.helpful ), render: ( r ) => ( r.helpful === null ? '—' : `${ r.helpful }% (${ r.votes })` ) },
		{
			key: 'indexed',
			label: __( 'AI search', 'dev3-ai-helpdesk' ),
			hideOnMobile: true,
			render: ( r ) => ( r.status !== 'publish' ? <span className="dev3hd-muted">—</span> : r.indexed ? <Badge tone="success">{ __( 'Indexed', 'dev3-ai-helpdesk' ) }</Badge> : <Badge tone="warning">{ __( 'Not indexed', 'dev3-ai-helpdesk' ) }</Badge> ),
		},
		{ key: 'modified', label: __( 'Updated', 'dev3-ai-helpdesk' ), sortable: true, align: 'end', nowrap: true, render: ( r ) => timeAgo( r.modified ? r.modified.replace( ' ', 'T' ) + 'Z' : '' ) },
	];

	const pct = status && status.articles ? Math.round( ( ( status.articles - status.stale ) / status.articles ) * 100 ) : 0;
	return (
		<Stack gap={ 16 }>
			<PageHeader
				title={ __( 'Knowledge base', 'dev3-ai-helpdesk' ) }
				description={ __( 'Help articles power the public help centre, the ticket-form suggestions and DEV3 AI reply drafts.', 'dev3-ai-helpdesk' ) }
				actions={
					<Inline>
						{ config.pages && config.pages.help && (
							<Button variant="ghost" href={ config.pages.help } target="_blank" iconRight="external">
								{ __( 'View help centre', 'dev3-ai-helpdesk' ) }
							</Button>
						) }
						<Button variant="ghost" href={ config.kbCatsUrl }>
							{ __( 'Categories', 'dev3-ai-helpdesk' ) }
						</Button>
						<Button variant="primary" icon="plus" href={ config.newKbUrl }>
							{ __( 'New article', 'dev3-ai-helpdesk' ) }
						</Button>
					</Inline>
				}
			/>
			<Columns min={ 320 }>
				<Card
					title={ __( 'AI search index', 'dev3-ai-helpdesk' ) }
					description={ __( 'Embeddings let customers and DEV3 AI find articles by meaning, not just keywords. Articles re-index when you save them.', 'dev3-ai-helpdesk' ) }
					footer={
						<Button icon="refresh" loading={ busy === 'index' } disabled={ ! ai || !! ( job && [ 'queued', 'running' ].includes( job.status ) ) } onClick={ reindex }>
							{ __( 'Rebuild index', 'dev3-ai-helpdesk' ) }
						</Button>
					}
				>
					{ status && (
						<>
							<ProgressBar value={ pct } label={ sprintf( /* translators: 1: indexed, 2: total. */ __( '%1$d of %2$d published articles indexed', 'dev3-ai-helpdesk' ), status.articles - status.stale, status.articles ) } tone={ status.stale ? 'warning' : 'success' } />
							{ ! ai && <p className="dev3hd-muted">{ __( 'Connect DEV3 AI in API Config to enable semantic search. Keyword search always works.', 'dev3-ai-helpdesk' ) }</p> }
						</>
					) }
					{ job && (
						<JobProgress
							jobId={ job.id }
							job={ job }
							title={ __( 'Indexing articles', 'dev3-ai-helpdesk' ) }
							onDone={ () => {
								load();
								onUsage();
							} }
						/>
					) }
				</Card>
				<Card
					tone="ai"
					title={ __( 'DEV3 AI article gaps', 'dev3-ai-helpdesk' ) }
					description={ __( 'Finds questions customers keep asking that no article answers yet, from your recently solved tickets.', 'dev3-ai-helpdesk' ) }
					actions={ gaps && gaps.at ? <span className="dev3hd-muted">{ timeAgo( gaps.at.replace( ' ', 'T' ) + 'Z' ) }</span> : null }
				>
					<ErrorNotice error={ gapError } onRetry={ findGaps } onDismiss={ () => setGapError( null ) } />
					{ gaps && gaps.gaps && gaps.gaps.length > 0 && (
						<ol className="dev3hd-gaps">
							{ gaps.gaps.map( ( g, i ) => (
								<li key={ i }>
									<div className="dev3hd-gaps__q">
										<strong>{ g.question }</strong>
										<Badge tone="brand">{ sprintf( /* translators: %d: tickets. */ __( '%d tickets', 'dev3-ai-helpdesk' ), g.count ) }</Badge>
									</div>
									{ g.title && (
										<span className="dev3hd-muted">
											<Icon name="file" size={ 12 } /> { sprintf( /* translators: %s: title. */ __( 'Suggested article: %s', 'dev3-ai-helpdesk' ), g.title ) }
										</span>
									) }
									{ g.examples.length > 0 && (
										<span className="dev3hd-gaps__ex">
											{ g.examples.map( ( e ) => (
												<a key={ e.id } href={ `#/tickets/${ e.id }` }>
													#{ e.number }
												</a>
											) ) }
										</span>
									) }
								</li>
							) ) }
						</ol>
					) }
					{ gaps && gaps.gaps && ! gaps.gaps.length && <p className="dev3hd-muted">{ __( 'No gaps found: your articles cover the recent questions.', 'dev3-ai-helpdesk' ) }</p> }
					<AiButton loading={ busy === 'gaps' } loadingText={ __( 'Analysing tickets…', 'dev3-ai-helpdesk' ) } disabled={ ! ai } onClick={ findGaps }>
						{ gaps ? __( 'Run again with DEV3 AI', 'dev3-ai-helpdesk' ) : __( 'Find gaps with DEV3 AI', 'dev3-ai-helpdesk' ) }
					</AiButton>
					<p className="dev3hd-muted">{ __( 'Open an example ticket and use “Create help article with DEV3 AI” to draft the missing article.', 'dev3-ai-helpdesk' ) }</p>
				</Card>
			</Columns>
			<Card title={ __( 'Articles', 'dev3-ai-helpdesk' ) } flush>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					caption={ __( 'Help articles', 'dev3-ai-helpdesk' ) }
					rows={ rows || [] }
					loading={ ! rows && ! error }
					columns={ columns }
					pageSize={ 20 }
					defaultSort={ { key: 'views', dir: 'desc' } }
					onRowClick={ ( r ) => {
						window.location.href = r.edit;
					} }
					empty={ { icon: 'file', title: __( 'No help articles yet', 'dev3-ai-helpdesk' ), description: __( 'Write your first article, or turn a solved ticket into one with DEV3 AI.', 'dev3-ai-helpdesk' ), action: <Button variant="primary" href={ config.newKbUrl }>{ __( 'New article', 'dev3-ai-helpdesk' ) }</Button> } }
				/>
			</Card>
		</Stack>
	);
}
