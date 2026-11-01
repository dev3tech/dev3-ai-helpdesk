import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, Checkbox, ErrorNotice, FormGrid, Icon, Inline, Modal, PageHeader, ResponsiveTable, Select, Tabs, TextField, Textarea, useToast } from '../core3/ui';
import { PRIORITY_TONES, SENTIMENT, STATUS_TONES, download, priorityLabel, priorityOptions, qs, slaBadge, statusLabel, statusOptions, timeAgo, useBoot } from './util';

const TABS = [
	{ id: 'open', status: 'active', label: __( 'Open', 'dev3-ai-helpdesk' ), count: 'active' },
	{ id: 'mine', status: 'active', view: 'mine', label: __( 'Mine', 'dev3-ai-helpdesk' ), count: 'mine' },
	{ id: 'unassigned', status: 'active', view: 'unassigned', label: __( 'Unassigned', 'dev3-ai-helpdesk' ), count: 'unassigned' },
	{ id: 'overdue', view: 'overdue', label: __( 'Overdue', 'dev3-ai-helpdesk' ), count: 'overdue' },
	{ id: 'pending', status: 'pending', label: __( 'Waiting', 'dev3-ai-helpdesk' ), count: 'pending' },
	{ id: 'resolved', status: 'resolved', label: __( 'Resolved', 'dev3-ai-helpdesk' ), count: 'resolved' },
	{ id: 'closed', status: 'closed', label: __( 'Closed', 'dev3-ai-helpdesk' ), count: 'closed' },
	{ id: 'all', status: '', label: __( 'All', 'dev3-ai-helpdesk' ), count: 'all' },
];

const PER_PAGE = 20;

/**
 * "New ticket" dialog (an agent opens a ticket for a customer).
 *
 * @param {Object}   props         Props.
 * @param {boolean}  props.open    Open.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onDone  Created ( ticket ).
 * @return {Element} Modal.
 */
export function NewTicket( { open, onClose, onDone } ) {
	const boot = useBoot();
	const toast = useToast();
	const blank = { name: '', email: '', subject: '', message: '', category: '', priority: 'normal', agent_id: '' };
	const [ form, setForm ] = useState( blank );
	const [ busy, setBusy ] = useState( false );
	const [ errors, setErrors ] = useState( {} );
	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	useEffect( () => {
		if ( open ) {
			setForm( blank );
			setErrors( {} );
		}
	}, [ open ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const submit = () => {
		const e = {};
		if ( ! /.+@.+\..+/.test( form.email ) ) {
			e.email = __( 'Enter the customer’s email.', 'dev3-ai-helpdesk' );
		}
		if ( ! form.subject.trim() ) {
			e.subject = __( 'Add a subject.', 'dev3-ai-helpdesk' );
		}
		if ( ! form.message.trim() ) {
			e.message = __( 'Describe the request.', 'dev3-ai-helpdesk' );
		}
		setErrors( e );
		if ( Object.keys( e ).length ) {
			return;
		}
		setBusy( true );
		request( 'tickets', { method: 'POST', data: form } )
			.then( ( t ) => {
				/* translators: %d: ticket number. */
				toast.success( sprintf( __( 'Ticket #%d created.', 'dev3-ai-helpdesk' ), t.number ) );
				onDone( t );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Modal
			open={ open }
			onClose={ onClose }
			size="lg"
			title={ __( 'New ticket', 'dev3-ai-helpdesk' ) }
			description={ __( 'Log a request from a phone call, chat or walk-in. The customer gets the usual confirmation email.', 'dev3-ai-helpdesk' ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-helpdesk' ) }
					</Button>
					<Button variant="primary" loading={ busy } onClick={ submit }>
						{ __( 'Create ticket', 'dev3-ai-helpdesk' ) }
					</Button>
				</>
			}
		>
			<FormGrid>
				<TextField label={ __( 'Customer name', 'dev3-ai-helpdesk' ) } value={ form.name } onChange={ set( 'name' ) } data-autofocus />
				<TextField label={ __( 'Customer email', 'dev3-ai-helpdesk' ) } type="email" required value={ form.email } onChange={ set( 'email' ) } error={ errors.email } />
			</FormGrid>
			<TextField label={ __( 'Subject', 'dev3-ai-helpdesk' ) } required value={ form.subject } onChange={ set( 'subject' ) } error={ errors.subject } />
			<Textarea label={ __( 'Message', 'dev3-ai-helpdesk' ) } required rows={ 5 } value={ form.message } onChange={ set( 'message' ) } error={ errors.message } />
			<FormGrid>
				<Select label={ __( 'Category', 'dev3-ai-helpdesk' ) } placeholder={ __( 'None', 'dev3-ai-helpdesk' ) } value={ form.category } onChange={ set( 'category' ) } options={ ( boot.categories || [] ).map( ( c ) => ( { value: c.id, label: c.name } ) ) } />
				<Select label={ __( 'Priority', 'dev3-ai-helpdesk' ) } value={ form.priority } onChange={ set( 'priority' ) } options={ priorityOptions() } />
				<Select label={ __( 'Assign to', 'dev3-ai-helpdesk' ) } placeholder={ __( 'Unassigned', 'dev3-ai-helpdesk' ) } value={ String( form.agent_id ) } onChange={ set( 'agent_id' ) } options={ ( boot.agents || [] ).map( ( a ) => ( { value: String( a.id ), label: a.name } ) ) } />
			</FormGrid>
		</Modal>
	);
}

export default function Tickets( { navigate, onChange } ) {
	const boot = useBoot();
	const toast = useToast();
	const [ tab, setTab ] = useState( 'open' );
	const [ filters, setFilters ] = useState( { department: '', category: '', priority: '', agent: '', search: '' } );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ sort, setSort ] = useState( { key: 'updated', dir: 'desc' } );
	const [ data, setData ] = useState( { rows: [], total: 0, counts: {} } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ confirm, setConfirm ] = useState( false );
	const timer = useRef();

	const query = () => {
		const t = TABS.find( ( x ) => x.id === tab );
		return qs( { ...filters, status: t.status || '', view: t.view || '', page, per_page: PER_PAGE, orderby: sort.key, order: sort.dir } );
	};
	const load = () => {
		setLoading( true );
		setError( null );
		request( `tickets?${ query() }` )
			.then( ( res ) => {
				setData( res );
				setSelected( ( sel ) => sel.filter( ( id ) => res.rows.some( ( r ) => r.id === id ) ) );
			} )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ tab, filters, page, sort ] );

	const onSearch = ( value ) => {
		setSearch( value );
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => {
			setPage( 1 );
			setFilters( ( f ) => ( { ...f, search: value } ) );
		}, 350 );
	};
	const setFilter = ( key, value ) => {
		setPage( 1 );
		setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
	};
	const bulk = ( action, value ) => {
		if ( ! value && action !== 'delete' ) {
			return;
		}
		setBusy( true );
		request( 'tickets/bulk', { method: 'POST', data: { ids: selected, action, value } } )
			.then( ( res ) => {
				/* translators: %d: number of tickets. */
				toast.success( sprintf( __( '%d tickets updated.', 'dev3-ai-helpdesk' ), res.updated ) );
				setSelected( [] );
				setConfirm( false );
				load();
				onChange();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const exportCsv = () =>
		request( `tickets/export?${ query() }` )
			.then( ( res ) => download( res.filename, res.csv ) )
			.catch( ( e ) => toast.error( e ) );

	const toggle = ( id, on ) => setSelected( ( sel ) => ( on ? [ ...new Set( [ ...sel, id ] ) ] : sel.filter( ( x ) => x !== id ) ) );
	const stop = ( e ) => e.stopPropagation();
	const allOnPage = data.rows.length > 0 && data.rows.every( ( r ) => selected.includes( r.id ) );
	const agents = ( boot.agents || [] ).map( ( a ) => ( { value: String( a.id ), label: a.name } ) );

	const columns = [
		{
			key: 'select',
			label: __( 'Select', 'dev3-ai-helpdesk' ),
			width: 44,
			hideOnMobile: true,
			render: ( r ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span onClick={ stop } className="dev3hd-select-cell">
					<input type="checkbox" className="dev3hd-rowcheck" aria-label={ sprintf( /* translators: %d: ticket number. */ __( 'Select ticket #%d', 'dev3-ai-helpdesk' ), r.number ) } checked={ selected.includes( r.id ) } onChange={ ( e ) => toggle( r.id, e.target.checked ) } />
				</span>
			),
		},
		{
			key: 'subject',
			label: __( 'Ticket', 'dev3-ai-helpdesk' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3hd-cell-main">
					<span className="dev3hd-ticket-title">
						{ r.last_reply_by === 'customer' && [ 'new', 'open' ].includes( r.status ) && <span className="dev3hd-unread" title={ __( 'Customer replied last', 'dev3-ai-helpdesk' ) } aria-hidden="true" /> }
						<strong>{ r.subject }</strong>
					</span>
					<span className="dev3hd-muted">
						#{ r.number } · { r.name || r.email }
						{ r.category_name ? ` · ${ r.category_name }` : '' }
					</span>
					{ ( r.has_triage || r.has_draft || ( r.sentiment && r.sentiment !== 'neutral' ) ) && (
						<span className="dev3hd-ai-hints">
							{ r.sentiment && r.sentiment !== 'neutral' && SENTIMENT[ r.sentiment ] && <Badge tone={ SENTIMENT[ r.sentiment ].tone }>{ SENTIMENT[ r.sentiment ].label }</Badge> }
							{ r.has_triage && (
								<span className="dev3hd-ai-hint">
									<Icon name="sparkles" size={ 12 } /> { __( 'Triage ready', 'dev3-ai-helpdesk' ) }
								</span>
							) }
							{ r.has_draft && (
								<span className="dev3hd-ai-hint">
									<Icon name="sparkles" size={ 12 } /> { __( 'Draft ready', 'dev3-ai-helpdesk' ) }
								</span>
							) }
						</span>
					) }
				</span>
			),
		},
		{ key: 'status', label: __( 'Status', 'dev3-ai-helpdesk' ), nowrap: true, render: ( r ) => <Badge tone={ STATUS_TONES[ r.status ] }>{ statusLabel( r.status ) }</Badge> },
		{ key: 'priority', label: __( 'Priority', 'dev3-ai-helpdesk' ), sortable: true, nowrap: true, render: ( r ) => <Badge tone={ PRIORITY_TONES[ r.priority ] } dot>{ priorityLabel( r.priority ) }</Badge> },
		{
			key: 'agent',
			label: __( 'Agent', 'dev3-ai-helpdesk' ),
			hideOnMobile: true,
			render: ( r ) =>
				r.agent ? (
					<span className="dev3hd-agent">
						<img src={ r.agent.avatar } alt="" width="20" height="20" />
						{ r.agent.name }
					</span>
				) : (
					<span className="dev3hd-muted">{ __( 'Unassigned', 'dev3-ai-helpdesk' ) }</span>
				),
		},
		{
			key: 'due',
			label: __( 'SLA', 'dev3-ai-helpdesk' ),
			sortable: true,
			nowrap: true,
			render: ( r ) => {
				const b = slaBadge( r.sla );
				return b ? <Badge tone={ b.tone }>{ b.label }</Badge> : <span className="dev3hd-muted">—</span>;
			},
		},
		{ key: 'updated', label: __( 'Updated', 'dev3-ai-helpdesk' ), sortable: true, align: 'end', nowrap: true, render: ( r ) => <span title={ r.updated_at }>{ timeAgo( r.updated_at ) }</span> },
	];

	const counts = data.counts || {};
	return (
		<>
			<PageHeader
				title={ __( 'Tickets', 'dev3-ai-helpdesk' ) }
				description={ __( 'Every conversation from the portal, forms and email. Open one to reply, add notes or let DEV3 AI help.', 'dev3-ai-helpdesk' ) }
				actions={
					<Inline>
						{ boot.me && boot.me.manager && (
							<Button icon="external" variant="ghost" onClick={ exportCsv }>
								{ __( 'Export CSV', 'dev3-ai-helpdesk' ) }
							</Button>
						) }
						<Button variant="primary" icon="plus" onClick={ () => setCreating( true ) }>
							{ __( 'New ticket', 'dev3-ai-helpdesk' ) }
						</Button>
					</Inline>
				}
			/>
			<Tabs
				label={ __( 'Ticket views', 'dev3-ai-helpdesk' ) }
				variant="pills"
				value={ tab }
				onChange={ ( v ) => {
					setTab( v );
					setPage( 1 );
					setSelected( [] );
				} }
				tabs={ TABS.map( ( t ) => ( { id: t.id, label: t.label, badge: counts[ t.count ] !== undefined ? counts[ t.count ] : undefined } ) ) }
			/>
			<Card flush>
				<div className="dev3hd-filters">
					<TextField label={ __( 'Search', 'dev3-ai-helpdesk' ) } hideLabel type="search" placeholder={ __( 'Search subject, #number, email or message', 'dev3-ai-helpdesk' ) } value={ search } onChange={ onSearch } />
					<Select label={ __( 'Department', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'All departments', 'dev3-ai-helpdesk' ) } value={ filters.department } onChange={ ( v ) => setFilter( 'department', v ) } options={ ( boot.departments || [] ).map( ( d ) => ( { value: d.id, label: d.name } ) ) } />
					<Select label={ __( 'Category', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'All categories', 'dev3-ai-helpdesk' ) } value={ filters.category } onChange={ ( v ) => setFilter( 'category', v ) } options={ ( boot.categories || [] ).map( ( c ) => ( { value: c.id, label: c.name } ) ) } />
					<Select label={ __( 'Priority', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'Any priority', 'dev3-ai-helpdesk' ) } value={ filters.priority } onChange={ ( v ) => setFilter( 'priority', v ) } options={ priorityOptions() } />
					<Select label={ __( 'Agent', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'Any agent', 'dev3-ai-helpdesk' ) } value={ filters.agent } onChange={ ( v ) => setFilter( 'agent', v ) } options={ [ { value: '0', label: __( 'Unassigned', 'dev3-ai-helpdesk' ) }, ...agents ] } />
				</div>
				{ selected.length > 0 && (
					<div className="dev3hd-bulkbar" role="region" aria-label={ __( 'Bulk actions', 'dev3-ai-helpdesk' ) }>
						<span>{ sprintf( /* translators: %d: number selected. */ __( '%d selected', 'dev3-ai-helpdesk' ), selected.length ) }</span>
						<div className="dev3hd-bulkbar__actions">
							<Select label={ __( 'Assign to', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'Assign to…', 'dev3-ai-helpdesk' ) } value="" onChange={ ( v ) => bulk( 'assign', v ) } options={ [ { value: '0', label: __( 'Unassigned', 'dev3-ai-helpdesk' ) }, ...agents ] } />
							<Select label={ __( 'Set status', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'Set status…', 'dev3-ai-helpdesk' ) } value="" onChange={ ( v ) => bulk( 'status', v ) } options={ statusOptions() } />
							<Select label={ __( 'Set priority', 'dev3-ai-helpdesk' ) } hideLabel placeholder={ __( 'Set priority…', 'dev3-ai-helpdesk' ) } value="" onChange={ ( v ) => bulk( 'priority', v ) } options={ priorityOptions() } />
							{ boot.me && boot.me.manager && (
								<Button size="sm" variant="danger" onClick={ () => setConfirm( true ) }>
									{ __( 'Delete', 'dev3-ai-helpdesk' ) }
								</Button>
							) }
							<Button size="sm" variant="link" onClick={ () => setSelected( [] ) }>
								{ __( 'Clear', 'dev3-ai-helpdesk' ) }
							</Button>
						</div>
					</div>
				) }
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<div className="dev3hd-selectall">
					<Checkbox label={ __( 'Select all on this page', 'dev3-ai-helpdesk' ) } checked={ allOnPage } onChange={ ( on ) => setSelected( on ? data.rows.map( ( r ) => r.id ) : [] ) } />
				</div>
				<ResponsiveTable
					caption={ __( 'Tickets', 'dev3-ai-helpdesk' ) }
					rows={ data.rows }
					loading={ loading }
					columns={ columns }
					sort={ sort }
					onSortChange={ ( s ) => {
						setSort( s );
						setPage( 1 );
					} }
					pagination={ { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } }
					onRowClick={ ( r ) => navigate( `tickets/${ r.id }` ) }
					empty={ {
						icon: 'inbox',
						title: __( 'No tickets here', 'dev3-ai-helpdesk' ),
						description: config.pages && config.pages.portal ? __( 'New tickets from your support page, forms and email show up in this list.', 'dev3-ai-helpdesk' ) : __( 'Publish the support portal (Settings → General) so customers can reach you.', 'dev3-ai-helpdesk' ),
					} }
				/>
			</Card>
			<NewTicket
				open={ creating }
				onClose={ () => setCreating( false ) }
				onDone={ ( t ) => {
					setCreating( false );
					onChange();
					navigate( `tickets/${ t.id }` );
				} }
			/>
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Delete these tickets?', 'dev3-ai-helpdesk' ) }
				description={ __( 'Tickets, their messages, notes and attachments are removed for good.', 'dev3-ai-helpdesk' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) }>
							{ __( 'Cancel', 'dev3-ai-helpdesk' ) }
						</Button>
						<Button variant="danger" loading={ busy } onClick={ () => bulk( 'delete' ) }>
							{ __( 'Delete', 'dev3-ai-helpdesk' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
