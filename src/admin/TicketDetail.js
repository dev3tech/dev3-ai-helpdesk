import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, EmptyState, ErrorNotice, Icon, Inline, Modal, Notice, PageHeader, Select, SkeletonCard, Stack, TextField, Textarea, useToast } from '../core3/ui';
import { PRIORITY_TONES, SENTIMENT, STATUS_TONES, channelLabel, dateTime, fileSize, initials, priorityLabel, priorityOptions, slaBadge, statusLabel, statusOptions, timeAgo, useBoot } from './util';

const REWRITES = [
	{ value: 'grammar', label: __( 'Fix spelling & grammar', 'dev3-ai-helpdesk' ) },
	{ value: 'friendly', label: __( 'Make it friendlier', 'dev3-ai-helpdesk' ) },
	{ value: 'formal', label: __( 'Make it more formal', 'dev3-ai-helpdesk' ) },
	{ value: 'shorter', label: __( 'Make it shorter', 'dev3-ai-helpdesk' ) },
	{ value: 'empathetic', label: __( 'Add empathy', 'dev3-ai-helpdesk' ) },
];

const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-helpdesk' ) },
	{ value: 'formal', label: __( 'Formal', 'dev3-ai-helpdesk' ) },
	{ value: 'concise', label: __( 'Concise', 'dev3-ai-helpdesk' ) },
	{ value: 'empathetic', label: __( 'Empathetic', 'dev3-ai-helpdesk' ) },
];

/**
 * Attachment chips.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.files Files.
 * @return {Element|null} List.
 */
function Attachments( { files } ) {
	if ( ! files || ! files.length ) {
		return null;
	}
	return (
		<ul className="dev3hd-files">
			{ files.map( ( f, i ) => (
				<li key={ i }>
					<a href={ f.url } target="_blank" rel="noopener noreferrer" className="dev3hd-file">
						{ f.image ? <img src={ f.url } alt="" width="40" height="40" loading="lazy" /> : <Icon name="file" size={ 18 } /> }
						<span className="dev3hd-file__name">{ f.name }</span>
						<span className="dev3hd-muted">{ fileSize( f.size ) }</span>
					</a>
				</li>
			) ) }
		</ul>
	);
}

/**
 * One timeline row.
 *
 * @param {Object} props   Props.
 * @param {Object} props.r Reply.
 * @return {Element} Row.
 */
function Message( { r } ) {
	if ( r.type === 'event' ) {
		return (
			<li className={ `dev3hd-event${ r.author === 'ai' ? ' is-ai' : '' }` }>
				{ r.author === 'ai' && <Icon name="sparkles" size={ 12 } /> }
				<span>{ r.body }</span>
				<time dateTime={ r.created_at } title={ dateTime( r.created_at ) }>
					{ timeAgo( r.created_at ) }
				</time>
			</li>
		);
	}
	const cls = r.type === 'note' ? 'is-note' : `is-${ r.author }`;
	return (
		<li className={ `dev3hd-msg ${ cls }` }>
			<span className="dev3hd-msg__avatar" aria-hidden="true">
				{ r.author === 'ai' ? <Icon name="sparkles" size={ 16 } /> : initials( r.name ) }
			</span>
			<div className="dev3hd-msg__body">
				<div className="dev3hd-msg__head">
					<strong>{ r.name || __( 'Customer', 'dev3-ai-helpdesk' ) }</strong>
					{ r.type === 'note' && <Badge tone="warning">{ __( 'Internal note', 'dev3-ai-helpdesk' ) }</Badge> }
					{ r.author === 'ai' && <Badge tone="brand">{ __( 'Sent by DEV3 AI', 'dev3-ai-helpdesk' ) }</Badge> }
					{ r.via === 'email' && <span className="dev3hd-muted">{ __( 'via email', 'dev3-ai-helpdesk' ) }</span> }
					<time className="dev3hd-muted" dateTime={ r.created_at } title={ dateTime( r.created_at ) }>
						{ timeAgo( r.created_at ) }
					</time>
				</div>
				{ r.body && <p className="dev3hd-pre">{ r.body }</p> }
				<Attachments files={ r.attachments } />
			</div>
		</li>
	);
}

/**
 * Reply / internal note composer with saved replies, attachments and DEV3 AI drafts.
 *
 * @param {Object}   props        Props.
 * @param {Object}   props.ticket Ticket.
 * @param {Function} props.onSent Sent ( ticket ).
 * @param {boolean}  props.ai     AI available.
 * @return {Element} Composer.
 */
function Composer( { ticket, onSent, ai } ) {
	const boot = useBoot();
	const toast = useToast();
	const [ mode, setMode ] = useState( 'reply' );
	const [ body, setBody ] = useState( '' );
	const [ files, setFiles ] = useState( [] );
	const [ status, setStatus ] = useState( 'pending' );
	const [ sending, setSending ] = useState( false );
	const [ draft, setDraft ] = useState( null );
	const [ drafting, setDrafting ] = useState( false );
	const [ tone, setTone ] = useState( 'friendly' );
	const [ rewrite, setRewrite ] = useState( null );
	const [ aiError, setAiError ] = useState( null );
	const fileRef = useRef();
	const opts = boot.options || {};
	const stored = ticket.ai && ticket.ai.draft && ! ticket.ai.draft.sent ? ticket.ai.draft : null;

	const insertCanned = ( id ) => {
		if ( ! id ) {
			return;
		}
		request( `canned/${ id }/use`, { method: 'POST', data: { ticket_id: ticket.id } } )
			.then( ( res ) => setBody( ( b ) => ( b.trim() ? `${ b.trim() }\n\n${ res.text }` : res.text ) ) )
			.catch( ( e ) => toast.error( e ) );
	};
	const genDraft = () => {
		setDrafting( true );
		setAiError( null );
		request( `tickets/${ ticket.id }/ai/draft`, { method: 'POST', data: { tone } } )
			.then( setDraft )
			.catch( setAiError )
			.finally( () => setDrafting( false ) );
	};
	const genRewrite = ( m ) => {
		if ( ! m || ! body.trim() ) {
			return;
		}
		setDrafting( true );
		setAiError( null );
		request( 'ai/rewrite', { method: 'POST', data: { text: body, mode: m } } )
			.then( ( res ) => setRewrite( { ...res, mode: m, original: body } ) )
			.catch( setAiError )
			.finally( () => setDrafting( false ) );
	};
	const addFiles = ( list ) => {
		const max = opts.max_files || 3;
		const next = [ ...files ];
		Array.from( list || [] ).forEach( ( f ) => {
			if ( next.length >= max ) {
				return;
			}
			if ( f.size > ( opts.max_file_mb || 5 ) * 1048576 ) {
				/* translators: %s: file name. */
				toast.error( sprintf( __( '%s is too large.', 'dev3-ai-helpdesk' ), f.name ) );
				return;
			}
			next.push( f );
		} );
		setFiles( next );
		if ( fileRef.current ) {
			fileRef.current.value = '';
		}
	};
	const send = () => {
		if ( ! body.trim() && ! files.length ) {
			toast.error( __( 'Write a message first.', 'dev3-ai-helpdesk' ) );
			return;
		}
		const fd = new window.FormData();
		fd.append( 'body', body );
		fd.append( 'type', mode );
		if ( mode === 'reply' && status ) {
			fd.append( 'status', status );
		}
		files.forEach( ( f ) => fd.append( 'files[]', f, f.name ) );
		setSending( true );
		request( `tickets/${ ticket.id }/replies`, { method: 'POST', body: fd } )
			.then( ( t ) => {
				setBody( '' );
				setFiles( [] );
				setDraft( null );
				toast.success( mode === 'note' ? __( 'Note added.', 'dev3-ai-helpdesk' ) : __( 'Reply sent.', 'dev3-ai-helpdesk' ) );
				onSent( t );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSending( false ) );
	};
	const shown = draft || ( stored && ! body ? stored : null );

	return (
		<Card className={ `dev3hd-composer is-${ mode }` }>
			<div className="dev3hd-composer__modes" role="tablist" aria-label={ __( 'Message type', 'dev3-ai-helpdesk' ) }>
				<button type="button" role="tab" aria-selected={ mode === 'reply' } className={ mode === 'reply' ? 'is-active' : '' } onClick={ () => setMode( 'reply' ) }>
					{ __( 'Reply to customer', 'dev3-ai-helpdesk' ) }
				</button>
				<button type="button" role="tab" aria-selected={ mode === 'note' } className={ mode === 'note' ? 'is-active' : '' } onClick={ () => setMode( 'note' ) }>
					{ __( 'Internal note', 'dev3-ai-helpdesk' ) }
				</button>
			</div>
			{ mode === 'note' && <p className="dev3hd-muted dev3hd-note-hint">{ __( 'Only agents see internal notes. They are never emailed to the customer.', 'dev3-ai-helpdesk' ) }</p> }
			<Textarea label={ mode === 'note' ? __( 'Internal note', 'dev3-ai-helpdesk' ) : __( 'Reply', 'dev3-ai-helpdesk' ) } hideLabel rows={ 7 } value={ body } onChange={ setBody } placeholder={ mode === 'note' ? __( 'Write a note for your team…', 'dev3-ai-helpdesk' ) : sprintf( /* translators: %s: customer name. */ __( 'Write your reply to %s…', 'dev3-ai-helpdesk' ), ticket.name ) } />
			{ files.length > 0 && (
				<ul className="dev3hd-pending-files">
					{ files.map( ( f, i ) => (
						<li key={ i }>
							<Icon name="file" size={ 14 } /> { f.name } <span className="dev3hd-muted">{ fileSize( f.size ) }</span>
							<Button size="sm" variant="ghost" icon="x" label={ sprintf( /* translators: %s: file name. */ __( 'Remove %s', 'dev3-ai-helpdesk' ), f.name ) } onClick={ () => setFiles( files.filter( ( _, j ) => j !== i ) ) } />
						</li>
					) ) }
				</ul>
			) }
			<div className="dev3hd-composer__tools">
				{ ( boot.canned || [] ).length > 0 && (
					<Select label={ __( 'Insert saved reply', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'Insert saved reply…', 'dev3-ai-helpdesk' ) } value="" onChange={ insertCanned } options={ boot.canned.filter( ( c ) => ! c.department || c.department === ticket.department ).map( ( c ) => ( { value: String( c.id ), label: c.title } ) ) } />
				) }
				{ opts.attachments && (
					<>
						<input ref={ fileRef } type="file" multiple className="dev3hd-sr" id={ `dev3hd-files-${ ticket.id }` } onChange={ ( e ) => addFiles( e.target.files ) } />
						<label htmlFor={ `dev3hd-files-${ ticket.id }` } className="dev3hd-ui-btn is-secondary is-md dev3hd-attach-btn">
							<Icon name="file" size={ 16 } /> { __( 'Attach', 'dev3-ai-helpdesk' ) }
						</label>
					</>
				) }
				{ ai && mode === 'reply' && (
					<>
						<Select label={ __( 'Draft tone', 'dev3-ai-helpdesk' ) } hideLabel value={ tone } onChange={ setTone } options={ TONES } />
						<AiButton loading={ drafting && ! rewrite } loadingText={ __( 'Drafting…', 'dev3-ai-helpdesk' ) } onClick={ genDraft }>
							{ __( 'Draft with DEV3 AI', 'dev3-ai-helpdesk' ) }
						</AiButton>
					</>
				) }
				{ ai && body.trim() && <Select label={ __( 'Improve with DEV3 AI', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'Improve with DEV3 AI…', 'dev3-ai-helpdesk' ) } value="" onChange={ genRewrite } options={ REWRITES } /> }
			</div>
			<ErrorNotice error={ aiError } onRetry={ genDraft } onDismiss={ () => setAiError( null ) } />
			{ shown && mode === 'reply' && (
				<div className="dev3hd-draft">
					{ ! draft && <Notice tone="ai" title={ __( 'DEV3 AI prepared a draft for this ticket', 'dev3-ai-helpdesk' ) }>{ __( 'Review it, edit anything, then use it. Nothing is sent until you click Send.', 'dev3-ai-helpdesk' ) }</Notice> }
					<AiDraftReview
						title={ __( 'DEV3 AI reply draft', 'dev3-ai-helpdesk' ) }
						draft={ shown.reply }
						onAccept={ ( text ) => {
							setBody( text );
							setDraft( null );
						} }
						onReject={ () => setDraft( null ) }
						onRegenerate={ genDraft }
						regenerating={ drafting }
						acceptLabel={ __( 'Use this draft', 'dev3-ai-helpdesk' ) }
						meta={ { model: shown.model, tokens: shown.tokens, cached: shown.cached } }
					/>
					<div className="dev3hd-draft__meta">
						<span>
							{ sprintf( /* translators: %d: percent. */ __( 'Confidence %d%%', 'dev3-ai-helpdesk' ), Math.round( ( shown.confidence || 0 ) * 100 ) ) }
						</span>
						{ shown.needs_human && <Badge tone="warning">{ __( 'Needs a human check', 'dev3-ai-helpdesk' ) }</Badge> }
						{ shown.missing_info && <span className="dev3hd-muted">{ shown.missing_info }</span> }
					</div>
					{ shown.sources && shown.sources.length > 0 ? (
						<div className="dev3hd-sources">
							<span className="dev3hd-label">{ __( 'Grounded in these help articles', 'dev3-ai-helpdesk' ) }</span>
							<ul>
								{ shown.sources.map( ( s ) => (
									<li key={ s.id }>
										<a href={ s.url } target="_blank" rel="noopener noreferrer">
											{ s.title } <Icon name="external" size={ 12 } />
										</a>
									</li>
								) ) }
							</ul>
						</div>
					) : (
						<p className="dev3hd-muted">{ __( 'No help article matched this question, so the draft asks for details instead of guessing.', 'dev3-ai-helpdesk' ) }</p>
					) }
				</div>
			) }
			{ rewrite && (
				<AiDraftReview
					title={ __( 'DEV3 AI suggested edit', 'dev3-ai-helpdesk' ) }
					original={ rewrite.original }
					draft={ rewrite.text }
					onAccept={ ( text ) => {
						setBody( text );
						setRewrite( null );
					} }
					onReject={ () => setRewrite( null ) }
					onRegenerate={ () => genRewrite( rewrite.mode ) }
					regenerating={ drafting }
					acceptLabel={ __( 'Replace my text', 'dev3-ai-helpdesk' ) }
					meta={ { model: rewrite.model, tokens: rewrite.tokens, cached: rewrite.cached } }
				/>
			) }
			<div className="dev3hd-composer__send">
				{ mode === 'reply' && <Select label={ __( 'Then set status to', 'dev3-ai-helpdesk' ) } value={ status } onChange={ setStatus } options={ statusOptions().filter( ( o ) => o.value !== 'new' ) } /> }
				<Button variant="primary" loading={ sending } onClick={ send } icon={ mode === 'note' ? 'edit' : 'check' }>
					{ mode === 'note' ? __( 'Add note', 'dev3-ai-helpdesk' ) : __( 'Send reply', 'dev3-ai-helpdesk' ) }
				</Button>
			</div>
		</Card>
	);
}

/**
 * WooCommerce orders of the customer.
 *
 * @param {Object} props    Props.
 * @param {number} props.id Ticket id.
 * @return {Element} Card.
 */
function OrdersCard( { id } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		request( `tickets/${ id }/orders` ).then( setData ).catch( setError );
	}, [ id ] );
	return (
		<Card title={ __( 'WooCommerce orders', 'dev3-ai-helpdesk' ) } flush>
			{ error && <ErrorNotice error={ error } /> }
			{ ! data && ! error && <SkeletonCard lines={ 2 } /> }
			{ data && ! data.orders.length && <EmptyState compact icon="box" title={ __( 'No orders found', 'dev3-ai-helpdesk' ) } description={ __( 'No orders for this customer’s account or email.', 'dev3-ai-helpdesk' ) } /> }
			{ data && data.orders.length > 0 && (
				<ul className="dev3hd-orders">
					{ data.orders.map( ( o ) => (
						<li key={ o.id } className={ o.linked ? 'is-linked' : '' }>
							<div className="dev3hd-orders__top">
								<a href={ o.edit_url } target="_blank" rel="noopener noreferrer">
									<strong>#{ o.number }</strong>
								</a>
								<Badge tone={ [ 'completed' ].includes( o.slug ) ? 'success' : [ 'failed', 'cancelled', 'refunded' ].includes( o.slug ) ? 'danger' : 'info' }>{ o.status }</Badge>
								<span className="dev3hd-orders__total">{ o.total }</span>
							</div>
							<span className="dev3hd-muted">
								{ o.date }
								{ o.linked ? ` · ${ __( 'linked to this ticket', 'dev3-ai-helpdesk' ) }` : '' }
							</span>
							<span className="dev3hd-orders__items">{ o.items.map( ( it ) => `${ it.qty } × ${ it.name }` ).join( ', ' ) }</span>
						</li>
					) ) }
				</ul>
			) }
		</Card>
	);
}

/**
 * Help-article builder (DEV3 AI) for a solved ticket.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.ticket  Ticket.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onDone  Created.
 * @return {Element} Modal.
 */
export function ArticleBuilder( { ticket, open, onClose, onDone } ) {
	const toast = useToast();
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const gen = () => {
		setBusy( true );
		setError( null );
		request( `tickets/${ ticket.id }/ai/article`, { method: 'POST' } )
			.then( setDraft )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	useEffect( () => {
		if ( open && ! draft && ! busy ) {
			gen();
		}
	}, [ open ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const accept = ( values ) => {
		setSaving( true );
		request( 'kb/articles', { method: 'POST', data: { ...values, ticket_id: ticket.id } } )
			.then( ( res ) => {
				toast.show( { tone: 'success', title: __( 'Draft article created', 'dev3-ai-helpdesk' ), message: __( 'Review and publish it in the editor.', 'dev3-ai-helpdesk' ), action: { label: __( 'Open editor', 'dev3-ai-helpdesk' ), onClick: () => window.open( res.edit_url, '_blank' ) } } );
				setDraft( null );
				onDone( res );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setSaving( false ) );
	};
	return (
		<Modal open={ open } onClose={ onClose } size="xl" title={ __( 'DEV3 AI · Help article from this ticket', 'dev3-ai-helpdesk' ) } description={ __( 'Personal details are removed before the thread is sent. The article is saved as a draft for you to review.', 'dev3-ai-helpdesk' ) }>
			{ busy && ! draft && <SkeletonCard lines={ 6 } /> }
			<ErrorNotice error={ error } onRetry={ gen } />
			{ draft && (
				<AiDraftReview
					title={ __( 'DEV3 AI article draft', 'dev3-ai-helpdesk' ) }
					fields={ [
						{ key: 'title', label: __( 'Title', 'dev3-ai-helpdesk' ), maxLength: 120 },
						{ key: 'excerpt', label: __( 'Summary', 'dev3-ai-helpdesk' ), multiline: true, maxLength: 300 },
						{ key: 'content', label: __( 'Article (HTML)', 'dev3-ai-helpdesk' ), multiline: true, maxLength: 8000 },
						{ key: 'category', label: __( 'Category', 'dev3-ai-helpdesk' ), maxLength: 60 },
					] }
					draft={ { title: draft.title, excerpt: draft.excerpt, content: draft.content, category: draft.category } }
					onAccept={ accept }
					onReject={ onClose }
					onRegenerate={ gen }
					regenerating={ busy }
					accepting={ saving }
					acceptLabel={ __( 'Save as draft article', 'dev3-ai-helpdesk' ) }
					meta={ { model: draft.model, tokens: draft.tokens } }
				/>
			) }
		</Modal>
	);
}

export default function TicketDetail( { id, navigate, onChange } ) {
	const boot = useBoot();
	const toast = useToast();
	const [ ticket, setTicket ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ aiError, setAiError ] = useState( null );
	const [ tags, setTags ] = useState( '' );
	const [ article, setArticle ] = useState( false );
	const ai = !! ( boot.ai && boot.ai.enabled && boot.ai.key_set && ! boot.ai.cap_reached );

	const apply = ( t ) => {
		setTicket( t );
		setTags( ( t.tags || [] ).join( ', ' ) );
	};
	const load = () => {
		setError( null );
		request( `tickets/${ id }` ).then( apply ).catch( setError );
	};
	useEffect( load, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const patch = ( data ) => {
		setBusy( 'patch' );
		request( `tickets/${ id }`, { method: 'PATCH', data } )
			.then( ( t ) => {
				apply( t );
				onChange();
				toast.success( __( 'Ticket updated.', 'dev3-ai-helpdesk' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};
	const runAi = ( path, key, data = {} ) => {
		setBusy( key );
		setAiError( null );
		return request( `tickets/${ id }/ai/${ path }`, { method: 'POST', data } )
			.then( ( res ) => {
				if ( res && res.replies ) {
					apply( res );
				} else {
					load();
				}
				onChange();
				return res;
			} )
			.catch( setAiError )
			.finally( () => setBusy( '' ) );
	};

	// Long threads: summarise automatically when opened.
	useEffect( () => {
		if ( ticket && ai && ticket.summary_suggest && ! ( ticket.ai && ticket.ai.summary ) ) {
			runAi( 'summary', 'summary' );
		}
	}, [ ticket && ticket.id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( error ) {
		return (
			<>
				<PageHeader title={ __( 'Ticket', 'dev3-ai-helpdesk' ) } back={ { href: '#/tickets', label: __( 'All tickets', 'dev3-ai-helpdesk' ) } } />
				<ErrorNotice error={ error } onRetry={ load } />
			</>
		);
	}
	if ( ! ticket ) {
		return <SkeletonCard lines={ 8 } />;
	}
	const t = ticket;
	const triage = t.ai && t.ai.triage;
	const summary = t.ai && t.ai.summary;
	const sla = slaBadge( t.sla );
	const done = [ 'resolved', 'closed' ].includes( t.status );
	const agents = ( boot.agents || [] ).map( ( a ) => ( { value: String( a.id ), label: a.name } ) );

	return (
		<>
			<PageHeader
				eyebrow={ sprintf( /* translators: 1: number, 2: channel. */ __( 'Ticket #%1$d · via %2$s', 'dev3-ai-helpdesk' ), t.number, channelLabel( t.channel ) ) }
				title={ t.subject }
				back={ { href: '#/tickets', label: __( 'All tickets', 'dev3-ai-helpdesk' ) } }
				actions={
					<Inline>
						{ ! done && (
							<Button variant="secondary" icon="check" loading={ busy === 'patch' } onClick={ () => patch( { status: 'resolved' } ) }>
								{ __( 'Resolve', 'dev3-ai-helpdesk' ) }
							</Button>
						) }
						{ done && boot.me && boot.me.can_kb && (
							<AiButton onClick={ () => setArticle( true ) } disabled={ ! ai }>
								{ __( 'Create help article with DEV3 AI', 'dev3-ai-helpdesk' ) }
							</AiButton>
						) }
					</Inline>
				}
			/>
			<div className="dev3hd-badges">
				<Badge tone={ STATUS_TONES[ t.status ] }>{ statusLabel( t.status ) }</Badge>
				<Badge tone={ PRIORITY_TONES[ t.priority ] } dot>
					{ priorityLabel( t.priority ) }
				</Badge>
				{ sla && <Badge tone={ sla.tone }>{ sla.label }</Badge> }
				{ t.sentiment && SENTIMENT[ t.sentiment ] && <Badge tone={ SENTIMENT[ t.sentiment ].tone }>{ SENTIMENT[ t.sentiment ].label }</Badge> }
				{ t.department_name && <Badge>{ t.department_name }</Badge> }
			</div>
			<div className="dev3hd-detail">
				<Stack gap={ 16 } className="dev3hd-detail__main">
					{ summary && (
						<Card
							tone="ai"
							title={ __( 'DEV3 AI thread summary', 'dev3-ai-helpdesk' ) }
							actions={
								<Button size="sm" variant="ghost" icon="refresh" loading={ busy === 'summary' } onClick={ () => runAi( 'summary', 'summary', { force: true } ) }>
									{ __( 'Refresh', 'dev3-ai-helpdesk' ) }
								</Button>
							}
						>
							{ t.summary_stale && <p className="dev3hd-muted">{ __( 'New messages arrived since this summary.', 'dev3-ai-helpdesk' ) }</p> }
							<p>{ summary.summary }</p>
							<dl className="dev3hd-dl">
								<dt>{ __( 'Customer wants', 'dev3-ai-helpdesk' ) }</dt>
								<dd>{ summary.customer_goal }</dd>
								{ summary.steps_taken && summary.steps_taken.length > 0 && (
									<>
										<dt>{ __( 'Done so far', 'dev3-ai-helpdesk' ) }</dt>
										<dd>
											<ul>
												{ summary.steps_taken.map( ( s, i ) => (
													<li key={ i }>{ s }</li>
												) ) }
											</ul>
										</dd>
									</>
								) }
								{ summary.open_questions && summary.open_questions.length > 0 && (
									<>
										<dt>{ __( 'Open questions', 'dev3-ai-helpdesk' ) }</dt>
										<dd>
											<ul>
												{ summary.open_questions.map( ( s, i ) => (
													<li key={ i }>{ s }</li>
												) ) }
											</ul>
										</dd>
									</>
								) }
								<dt>{ __( 'Next step', 'dev3-ai-helpdesk' ) }</dt>
								<dd>{ summary.next_action }</dd>
							</dl>
						</Card>
					) }
					<Card title={ __( 'Conversation', 'dev3-ai-helpdesk' ) } description={ sprintf( /* translators: %d: messages. */ __( '%d messages', 'dev3-ai-helpdesk' ), t.messages ) } actions={ ! summary && ai && t.messages > 1 ? <Button size="sm" variant="ghost" icon="sparkles" loading={ busy === 'summary' } onClick={ () => runAi( 'summary', 'summary' ) }>{ __( 'Summarise with DEV3 AI', 'dev3-ai-helpdesk' ) }</Button> : null }>
						<ol className="dev3hd-thread">
							{ t.replies.map( ( r ) => (
								<Message key={ r.id } r={ r } />
							) ) }
						</ol>
					</Card>
					<ErrorNotice error={ aiError } onDismiss={ () => setAiError( null ) } />
					{ t.status === 'closed' && <Notice tone="info">{ __( 'This ticket is closed. Replying reopens it for the customer.', 'dev3-ai-helpdesk' ) }</Notice> }
					<Composer ticket={ t } ai={ ai } onSent={ ( next ) => { apply( next ); onChange(); } } />
				</Stack>
				<Stack gap={ 16 } className="dev3hd-detail__side">
					<Card title={ __( 'Properties', 'dev3-ai-helpdesk' ) }>
						<Select label={ __( 'Status', 'dev3-ai-helpdesk' ) } value={ t.status } onChange={ ( v ) => patch( { status: v } ) } options={ statusOptions() } />
						<Select label={ __( 'Priority', 'dev3-ai-helpdesk' ) } value={ t.priority } onChange={ ( v ) => patch( { priority: v } ) } options={ priorityOptions() } />
						<Select label={ __( 'Assignee', 'dev3-ai-helpdesk' ) } value={ String( t.agent ? t.agent.id : 0 ) } onChange={ ( v ) => patch( { agent_id: parseInt( v, 10 ) || 0 } ) } options={ [ { value: '0', label: __( 'Unassigned', 'dev3-ai-helpdesk' ) }, ...agents ] } />
						<Select label={ __( 'Department', 'dev3-ai-helpdesk' ) } value={ t.department } onChange={ ( v ) => patch( { department: v } ) } options={ ( boot.departments || [] ).map( ( d ) => ( { value: d.id, label: d.name } ) ) } />
						<Select label={ __( 'Category', 'dev3-ai-helpdesk' ) } placeholder={ __( 'None', 'dev3-ai-helpdesk' ) } value={ t.category } onChange={ ( v ) => patch( { category: v } ) } options={ ( boot.categories || [] ).map( ( c ) => ( { value: c.id, label: c.name } ) ) } />
						<TextField label={ __( 'Tags', 'dev3-ai-helpdesk' ) } help={ __( 'Comma separated. Saved when you leave the field.', 'dev3-ai-helpdesk' ) } value={ tags } onChange={ setTags } onBlur={ () => tags !== ( t.tags || [] ).join( ', ' ) && patch( { tags } ) } />
						{ ! t.agent && boot.me && (
							<Button block variant="secondary" onClick={ () => patch( { agent_id: boot.me.id } ) }>
								{ __( 'Assign to me', 'dev3-ai-helpdesk' ) }
							</Button>
						) }
					</Card>
					<Card
						tone="ai"
						title={ __( 'DEV3 AI triage', 'dev3-ai-helpdesk' ) }
						actions={
							triage ? (
								<Button size="sm" variant="ghost" icon="refresh" label={ __( 'Run triage again', 'dev3-ai-helpdesk' ) } loading={ busy === 'triage' } disabled={ ! ai } onClick={ () => runAi( 'triage', 'triage' ) } />
							) : null
						}
					>
						{ ! triage && (
							<>
								<p className="dev3hd-muted">{ ai ? __( 'Suggest a category, priority and sentiment for this ticket.', 'dev3-ai-helpdesk' ) : __( 'Connect DEV3 AI in API Config to triage tickets automatically.', 'dev3-ai-helpdesk' ) }</p>
								<AiButton loading={ busy === 'triage' } loadingText={ __( 'Triaging…', 'dev3-ai-helpdesk' ) } disabled={ ! ai } onClick={ () => runAi( 'triage', 'triage' ) }>
									{ __( 'Triage with DEV3 AI', 'dev3-ai-helpdesk' ) }
								</AiButton>
							</>
						) }
						{ triage && (
							<>
								<p>{ triage.summary }</p>
								<dl className="dev3hd-dl dev3hd-dl--grid">
									<dt>{ __( 'Category', 'dev3-ai-helpdesk' ) }</dt>
									<dd>{ ( ( boot.categories || [] ).find( ( c ) => c.id === triage.category ) || {} ).name || '—' }</dd>
									<dt>{ __( 'Priority', 'dev3-ai-helpdesk' ) }</dt>
									<dd>
										<Badge tone={ PRIORITY_TONES[ triage.priority ] } dot>
											{ priorityLabel( triage.priority ) }
										</Badge>
									</dd>
									<dt>{ __( 'Sentiment', 'dev3-ai-helpdesk' ) }</dt>
									<dd>{ SENTIMENT[ triage.sentiment ] ? <Badge tone={ SENTIMENT[ triage.sentiment ].tone }>{ SENTIMENT[ triage.sentiment ].label }</Badge> : '—' }</dd>
									{ triage.language && (
										<>
											<dt>{ __( 'Language', 'dev3-ai-helpdesk' ) }</dt>
											<dd>{ triage.language }</dd>
										</>
									) }
									<dt>{ __( 'Confidence', 'dev3-ai-helpdesk' ) }</dt>
									<dd>{ Math.round( triage.confidence * 100 ) }%</dd>
								</dl>
								{ triage.tags && triage.tags.length > 0 && (
									<p className="dev3hd-tags">
										{ triage.tags.map( ( tag ) => (
											<Badge key={ tag }>{ tag }</Badge>
										) ) }
									</p>
								) }
								{ triage.applied ? (
									<p className="dev3hd-muted">
										<Icon name="check" size={ 14 } /> { __( 'Applied to this ticket.', 'dev3-ai-helpdesk' ) }
									</p>
								) : (
									<Inline>
										<Button variant="primary" size="sm" icon="check" loading={ busy === 'apply' } onClick={ () => runAi( 'apply-triage', 'apply' ) }>
											{ __( 'Apply triage', 'dev3-ai-helpdesk' ) }
										</Button>
									</Inline>
								) }
							</>
						) }
					</Card>
					<Card title={ __( 'Customer', 'dev3-ai-helpdesk' ) }>
						<div className="dev3hd-customer">
							<img src={ t.customer.avatar } alt="" width="44" height="44" />
							<div>
								<strong>{ t.customer.name }</strong>
								<a href={ `mailto:${ t.customer.email }` } className="dev3hd-break">
									{ t.customer.email }
								</a>
								{ t.customer.registered ? <span className="dev3hd-muted">{ __( 'Registered customer', 'dev3-ai-helpdesk' ) }</span> : <span className="dev3hd-muted">{ __( 'Guest', 'dev3-ai-helpdesk' ) }</span> }
							</div>
						</div>
						<Button
							size="sm"
							variant="ghost"
							icon="copy"
							onClick={ () => {
								if ( window.navigator.clipboard ) {
									window.navigator.clipboard.writeText( t.portal_url );
								}
								toast.info( __( 'Customer link copied.', 'dev3-ai-helpdesk' ) );
							} }
						>
							{ __( 'Copy customer link', 'dev3-ai-helpdesk' ) }
						</Button>
						{ t.customer.tickets.length > 0 && (
							<>
								<span className="dev3hd-label">{ __( 'Other tickets', 'dev3-ai-helpdesk' ) }</span>
								<ul className="dev3hd-mini-list">
									{ t.customer.tickets.map( ( o ) => (
										<li key={ o.id }>
											<a href={ `#/tickets/${ o.id }` }>
												#{ o.number } { o.subject }
											</a>
											<Badge tone={ STATUS_TONES[ o.status ] }>{ statusLabel( o.status ) }</Badge>
										</li>
									) ) }
								</ul>
							</>
						) }
					</Card>
					{ t.sla && ( t.sla.first || t.sla.resolve ) && (
						<Card title={ __( 'SLA', 'dev3-ai-helpdesk' ) }>
							<dl className="dev3hd-dl dev3hd-dl--grid">
								{ t.sla.first && (
									<>
										<dt>{ __( 'First response', 'dev3-ai-helpdesk' ) }</dt>
										<dd>{ t.sla.first.met === null ? ( t.sla.first.overdue ? <Badge tone="danger">{ __( 'Overdue', 'dev3-ai-helpdesk' ) }</Badge> : dateTime( t.sla.first.due.replace( ' ', 'T' ) + 'Z' ) ) : <Badge tone={ t.sla.first.met ? 'success' : 'danger' }>{ t.sla.first.met ? __( 'Met', 'dev3-ai-helpdesk' ) : __( 'Missed', 'dev3-ai-helpdesk' ) }</Badge> }</dd>
									</>
								) }
								{ t.sla.resolve && (
									<>
										<dt>{ __( 'Resolution', 'dev3-ai-helpdesk' ) }</dt>
										<dd>{ t.sla.paused ? __( 'Paused (waiting on customer)', 'dev3-ai-helpdesk' ) : t.sla.resolve.met === null ? ( t.sla.resolve.overdue ? <Badge tone="danger">{ __( 'Overdue', 'dev3-ai-helpdesk' ) }</Badge> : dateTime( t.sla.resolve.due.replace( ' ', 'T' ) + 'Z' ) ) : <Badge tone={ t.sla.resolve.met ? 'success' : 'danger' }>{ t.sla.resolve.met ? __( 'Met', 'dev3-ai-helpdesk' ) : __( 'Missed', 'dev3-ai-helpdesk' ) }</Badge> }</dd>
									</>
								) }
							</dl>
						</Card>
					) }
					{ t.rating > 0 && (
						<Card title={ __( 'Customer rating', 'dev3-ai-helpdesk' ) }>
							<Badge tone={ t.rating === 3 ? 'success' : t.rating === 2 ? 'warning' : 'danger' }>{ t.rating_label }</Badge>
							{ t.rating_comment && <p className="dev3hd-pre">“{ t.rating_comment }”</p> }
						</Card>
					) }
					{ boot.options && boot.options.woo && <OrdersCard id={ t.id } /> }
				</Stack>
			</div>
			{ article && (
				<ArticleBuilder
					ticket={ t }
					open={ article }
					onClose={ () => setArticle( false ) }
					onDone={ () => {
						setArticle( false );
						load();
					} }
				/>
			) }
			{ navigate && null }
		</>
	);
}
