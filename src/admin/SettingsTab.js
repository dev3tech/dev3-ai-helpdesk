import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, Checkbox, ColorField, ErrorNotice, FormGrid, IconButton, Inline, JobProgress, Notice, NumberField, PageHeader, ResponsiveTable, Select, Stack, Tabs, TextField, Textarea, Toggle, useToast } from '../core3/ui';
import { priorityLabel, timeAgo } from './util';

const SECTIONS = [
	{ id: 'general', label: __( 'General', 'dev3-ai-helpdesk' ) },
	{ id: 'departments', label: __( 'Departments', 'dev3-ai-helpdesk' ) },
	{ id: 'agents', label: __( 'Agents', 'dev3-ai-helpdesk' ) },
	{ id: 'sla', label: __( 'SLA', 'dev3-ai-helpdesk' ) },
	{ id: 'emails', label: __( 'Emails', 'dev3-ai-helpdesk' ) },
	{ id: 'mail', label: __( 'Email piping', 'dev3-ai-helpdesk' ) },
	{ id: 'ai', label: __( 'DEV3 AI', 'dev3-ai-helpdesk' ) },
];

const DAYS = [
	{ value: 1, label: __( 'Mon', 'dev3-ai-helpdesk' ) },
	{ value: 2, label: __( 'Tue', 'dev3-ai-helpdesk' ) },
	{ value: 3, label: __( 'Wed', 'dev3-ai-helpdesk' ) },
	{ value: 4, label: __( 'Thu', 'dev3-ai-helpdesk' ) },
	{ value: 5, label: __( 'Fri', 'dev3-ai-helpdesk' ) },
	{ value: 6, label: __( 'Sat', 'dev3-ai-helpdesk' ) },
	{ value: 0, label: __( 'Sun', 'dev3-ai-helpdesk' ) },
];

const NOTIFY = {
	customer_new: __( 'Customer: ticket received (with private link)', 'dev3-ai-helpdesk' ),
	customer_reply: __( 'Customer: new reply from your team', 'dev3-ai-helpdesk' ),
	customer_resolved: __( 'Customer: ticket resolved + rating request', 'dev3-ai-helpdesk' ),
	agent_new: __( 'Team: new ticket', 'dev3-ai-helpdesk' ),
	agent_reply: __( 'Team: customer replied', 'dev3-ai-helpdesk' ),
	agent_assigned: __( 'Agent: ticket assigned to you', 'dev3-ai-helpdesk' ),
	sla_breach: __( 'Team: SLA target missed', 'dev3-ai-helpdesk' ),
};

/**
 * Save bar.
 *
 * @param {Object}   props        Props.
 * @param {boolean}  props.dirty  Changed.
 * @param {boolean}  props.busy   Saving.
 * @param {Function} props.onSave Save.
 * @return {Element} Bar.
 */
function SaveBar( { dirty, busy, onSave } ) {
	return (
		<div className={ `dev3hd-savebar${ dirty ? ' is-dirty' : '' }` }>
			{ dirty && <span className="dev3hd-muted">{ __( 'Unsaved changes', 'dev3-ai-helpdesk' ) }</span> }
			<Button variant="primary" loading={ busy } disabled={ ! dirty } onClick={ onSave }>
				{ __( 'Save settings', 'dev3-ai-helpdesk' ) }
			</Button>
		</div>
	);
}

/**
 * Agents section (WordPress users with the Helpdesk agent role, plus managers).
 *
 * @param {Object} props             Props.
 * @param {Array}  props.departments Departments.
 * @return {Element} Section.
 */
function Agents( { departments } ) {
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ user, setUser ] = useState( '' );
	const [ depts, setDepts ] = useState( [] );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		request( 'agents' ).then( setRows ).catch( setError );
	}, [] );
	const add = () => {
		if ( ! user.trim() ) {
			return;
		}
		setBusy( true );
		request( 'agents', { method: 'POST', data: { user, departments: depts } } )
			.then( ( r ) => {
				setRows( r );
				setUser( '' );
				setDepts( [] );
				toast.success( __( 'Agent added.', 'dev3-ai-helpdesk' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const remove = ( a ) =>
		request( `agents/${ a.id }`, { method: 'DELETE' } )
			.then( setRows )
			.catch( ( e ) => toast.error( e ) );
	const dname = ( id ) => ( departments.find( ( d ) => d.id === id ) || {} ).name || id;
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'Add an agent', 'dev3-ai-helpdesk' ) } description={ __( 'Agents get the “Helpdesk agent” role: they can answer tickets of their departments (and unassigned ones) but cannot change settings. Administrators are always helpdesk managers.', 'dev3-ai-helpdesk' ) } footer={ <Button variant="primary" icon="plus" loading={ busy } onClick={ add }>{ __( 'Add agent', 'dev3-ai-helpdesk' ) }</Button> }>
				<TextField label={ __( 'WordPress user name or email', 'dev3-ai-helpdesk' ) } value={ user } onChange={ setUser } help={ __( 'The user must exist already (Users → Add New).', 'dev3-ai-helpdesk' ) } />
				<fieldset className="dev3hd-fieldset">
					<legend>{ __( 'Departments', 'dev3-ai-helpdesk' ) }</legend>
					<div className="dev3hd-checks">
						{ departments.map( ( d ) => (
							<Checkbox key={ d.id } label={ d.name } checked={ depts.includes( d.id ) } onChange={ ( on ) => setDepts( on ? [ ...depts, d.id ] : depts.filter( ( x ) => x !== d.id ) ) } />
						) ) }
					</div>
				</fieldset>
			</Card>
			<Card title={ __( 'Team', 'dev3-ai-helpdesk' ) } flush>
				{ error && <ErrorNotice error={ error } /> }
				<ResponsiveTable
					caption={ __( 'Agents', 'dev3-ai-helpdesk' ) }
					rows={ rows || [] }
					loading={ ! rows && ! error }
					columns={ [
						{
							key: 'name',
							label: __( 'Agent', 'dev3-ai-helpdesk' ),
							primary: true,
							render: ( a ) => (
								<span className="dev3hd-agent">
									<img src={ a.avatar } alt="" width="24" height="24" />
									<span className="dev3hd-cell-main">
										<strong>{ a.name }</strong>
										<span className="dev3hd-muted">{ a.email }</span>
									</span>
								</span>
							),
						},
						{ key: 'role', label: __( 'Role', 'dev3-ai-helpdesk' ), render: ( a ) => ( a.manager ? <Badge tone="brand">{ __( 'Manager', 'dev3-ai-helpdesk' ) }</Badge> : <Badge>{ __( 'Agent', 'dev3-ai-helpdesk' ) }</Badge> ) },
						{ key: 'departments', label: __( 'Departments', 'dev3-ai-helpdesk' ), render: ( a ) => ( a.departments.length ? a.departments.map( dname ).join( ', ' ) : <span className="dev3hd-muted">{ __( 'None', 'dev3-ai-helpdesk' ) }</span> ) },
					] }
					rowActions={ ( a ) => ( a.manager ? null : <IconButton icon="trash" label={ sprintf( /* translators: %s: name. */ __( 'Remove %s', 'dev3-ai-helpdesk' ), a.name ) } onClick={ () => remove( a ) } /> ) }
				/>
			</Card>
		</Stack>
	);
}

/**
 * Email piping section (IMAP + webhook) with its own status calls.
 *
 * @param {Object}   props     Props.
 * @param {Object}   props.v   Values.
 * @param {Function} props.set Setter.
 * @return {Element} Section.
 */
function MailSection( { v, set } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState( null );
	const [ testing, setTesting ] = useState( false );
	const [ job, setJob ] = useState( null );
	const [ pass, setPass ] = useState( '' );
	const loadStatus = () =>
		request( 'mail/status' )
			.then( ( s ) => {
				setStatus( s );
				if ( s.job ) {
					setJob( s.job );
				}
			} )
			.catch( () => {} );
	useEffect( () => {
		loadStatus();
	}, [] );
	const test = () => {
		setTesting( true );
		request( 'mail/test', { method: 'POST', data: { host: v.imap_host, port: v.imap_port, encryption: v.imap_encryption, user: v.imap_user, folder: v.imap_folder, password: pass } } )
			.then( ( r ) => toast.success( sprintf( /* translators: %d: unread messages. */ __( 'Connected. %d unread messages waiting.', 'dev3-ai-helpdesk' ), r.unseen ) ) )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setTesting( false ) );
	};
	const poll = () =>
		request( 'mail/poll', { method: 'POST' } )
			.then( setJob )
			.catch( ( e ) => toast.error( e ) );
	const last = status && status.last;
	return (
		<Stack gap={ 16 }>
			<Card title={ __( 'IMAP mailbox', 'dev3-ai-helpdesk' ) } description={ __( 'Checks a support mailbox for new mail every few minutes. New mail opens tickets; replies (matched by [#number] or email threading) land on the right ticket.', 'dev3-ai-helpdesk' ) }>
				<Toggle label={ __( 'Poll this mailbox', 'dev3-ai-helpdesk' ) } checked={ !! v.imap_enabled } onChange={ set( 'imap_enabled' ) } />
				<FormGrid>
					<TextField label={ __( 'IMAP host', 'dev3-ai-helpdesk' ) } placeholder="imap.example.com" value={ v.imap_host } onChange={ set( 'imap_host' ) } />
					<NumberField label={ __( 'Port', 'dev3-ai-helpdesk' ) } min={ 1 } max={ 65535 } value={ v.imap_port } onChange={ set( 'imap_port' ) } />
					<Select label={ __( 'Security', 'dev3-ai-helpdesk' ) } value={ v.imap_encryption } onChange={ set( 'imap_encryption' ) } options={ [ { value: 'ssl', label: 'SSL/TLS (993)' }, { value: 'tls', label: 'STARTTLS (143)' }, { value: 'none', label: __( 'None (not recommended)', 'dev3-ai-helpdesk' ) } ] } />
					<TextField label={ __( 'User name', 'dev3-ai-helpdesk' ) } value={ v.imap_user } onChange={ set( 'imap_user' ) } autoComplete="off" />
					<TextField
						label={ __( 'Password', 'dev3-ai-helpdesk' ) }
						type="password"
						autoComplete="new-password"
						value={ pass }
						placeholder={ v.imap_password_set ? '••••••••' : '' }
						onChange={ ( p ) => {
							setPass( p );
							set( 'imap_password' )( p );
						} }
						help={ v.imap_password_set ? __( 'Stored encrypted. Leave empty to keep it. You can also define DEV3HD_IMAP_PASSWORD in wp-config.php.', 'dev3-ai-helpdesk' ) : __( 'Use an app password if your provider has them.', 'dev3-ai-helpdesk' ) }
					/>
					<TextField label={ __( 'Folder', 'dev3-ai-helpdesk' ) } value={ v.imap_folder } onChange={ set( 'imap_folder' ) } />
					<NumberField label={ __( 'Check every (minutes)', 'dev3-ai-helpdesk' ) } min={ 1 } max={ 60 } value={ v.imap_interval } onChange={ set( 'imap_interval' ) } />
					<NumberField label={ __( 'Messages per check', 'dev3-ai-helpdesk' ) } min={ 1 } max={ 100 } value={ v.imap_max } onChange={ set( 'imap_max' ) } />
				</FormGrid>
				<Inline>
					<Button loading={ testing } onClick={ test }>
						{ __( 'Test connection', 'dev3-ai-helpdesk' ) }
					</Button>
					<Button icon="refresh" onClick={ poll } disabled={ ! v.imap_host }>
						{ __( 'Check mail now', 'dev3-ai-helpdesk' ) }
					</Button>
				</Inline>
				{ last && (
					<p className="dev3hd-muted">
						{ last.error
							? sprintf( /* translators: 1: time, 2: error. */ __( 'Last check %1$s failed: %2$s', 'dev3-ai-helpdesk' ), timeAgo( last.at_iso ), last.error )
							: sprintf( /* translators: 1: time, 2: fetched, 3: created, 4: replies. */ __( 'Last check %1$s: %2$d fetched, %3$d new tickets, %4$d replies.', 'dev3-ai-helpdesk' ), timeAgo( last.at_iso ), last.fetched, last.created, last.replied ) }
					</p>
				) }
				{ job && <JobProgress jobId={ job.id } job={ job } title={ __( 'Checking mail', 'dev3-ai-helpdesk' ) } onDone={ loadStatus } /> }
			</Card>
			<Card title={ __( 'Inbound webhook', 'dev3-ai-helpdesk' ) } description={ __( 'Forward mail from Mailgun routes, SendGrid Inbound Parse, Postmark or any service that POSTs JSON or raw MIME.', 'dev3-ai-helpdesk' ) }>
				<Toggle label={ __( 'Accept inbound email at this URL', 'dev3-ai-helpdesk' ) } checked={ !! v.webhook_enabled } onChange={ set( 'webhook_enabled' ) } />
				{ status && (
					<TextField
						label={ __( 'Webhook URL (keep it secret)', 'dev3-ai-helpdesk' ) }
						value={ status.webhook_url }
						readOnly
						onFocus={ ( e ) => e.target.select() }
						help={ __( 'Generic JSON fields: from, subject, text, html, message_id, in_reply_to, references, attachments[{filename, content (base64), content_type}].', 'dev3-ai-helpdesk' ) }
					/>
				) }
				<Button
					variant="ghost"
					size="sm"
					icon="refresh"
					onClick={ () =>
						request( 'settings', { method: 'POST', data: { regenerate_token: true } } )
							.then( () => loadStatus() )
							.then( () => toast.success( __( 'New secret created. Update your mail service.', 'dev3-ai-helpdesk' ) ) )
							.catch( ( e ) => toast.error( e ) )
					}
				>
					{ __( 'Regenerate secret', 'dev3-ai-helpdesk' ) }
				</Button>
			</Card>
			<Notice tone="info">
				{ sprintf( /* translators: %s: email. */ __( 'Notifications are sent from %s. Use the address of the mailbox above so customer replies come back to the helpdesk.', 'dev3-ai-helpdesk' ), ( status && status.from ) || '…' ) }
			</Notice>
		</Stack>
	);
}

export default function SettingsTab( { settings, save, section, navigate } ) {
	const toast = useToast();
	const active = SECTIONS.some( ( s ) => s.id === section ) ? section : 'general';
	const [ v, setV ] = useState( settings );
	const [ dirty, setDirty ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		setV( settings );
		setDirty( false );
	}, [ settings ] );
	const set = ( key ) => ( value ) => {
		setV( ( s ) => ( { ...s, [ key ]: value } ) );
		setDirty( true );
	};
	const setNested = ( key, sub, value ) => {
		setV( ( s ) => ( { ...s, [ key ]: { ...( s[ key ] || {} ), [ sub ]: value } } ) );
		setDirty( true );
	};
	const submit = () => {
		setBusy( true );
		const { api_key: apiKey, ...rest } = v; // eslint-disable-line no-unused-vars
		save( rest )
			.then( () => {
				setDirty( false );
				toast.success( __( 'Settings saved.', 'dev3-ai-helpdesk' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const createPages = () =>
		request( 'setup/pages', { method: 'POST' } )
			.then( ( p ) => {
				config.pages = p;
				toast.success( __( 'Pages created.', 'dev3-ai-helpdesk' ) );
			} )
			.catch( ( e ) => toast.error( e ) );

	const depts = v.departments || [];
	const cats = v.categories || [];
	const updDept = ( i, key, value ) => {
		const next = depts.map( ( d, j ) => ( j === i ? { ...d, [ key ]: value } : d ) );
		set( 'departments' )( next );
	};
	const updCat = ( i, key, value ) => {
		const next = cats.map( ( c, j ) => ( j === i ? { ...c, [ key ]: value } : c ) );
		set( 'categories' )( next );
	};

	const body = {
		general: (
			<Stack gap={ 16 }>
				<Card title={ __( 'Support pages', 'dev3-ai-helpdesk' ) } description={ __( 'Created on activation. Add the blocks or shortcodes [dev3hd_portal], [dev3hd_ticket_form] and [dev3hd_help_center] to any page.', 'dev3-ai-helpdesk' ) }>
					<ul className="dev3hd-mini-list">
						<li>
							<span>{ __( 'Support portal', 'dev3-ai-helpdesk' ) }</span>
							{ config.pages && config.pages.portal ? <a href={ config.pages.portal } target="_blank" rel="noopener noreferrer">{ config.pages.portal }</a> : <Badge tone="warning">{ __( 'Missing', 'dev3-ai-helpdesk' ) }</Badge> }
						</li>
						<li>
							<span>{ __( 'Help centre', 'dev3-ai-helpdesk' ) }</span>
							{ config.pages && config.pages.help ? <a href={ config.pages.help } target="_blank" rel="noopener noreferrer">{ config.pages.help }</a> : <Badge tone="warning">{ __( 'Missing', 'dev3-ai-helpdesk' ) }</Badge> }
						</li>
					</ul>
					<Button size="sm" onClick={ createPages }>
						{ __( 'Create missing pages', 'dev3-ai-helpdesk' ) }
					</Button>
				</Card>
				<Card title={ __( 'Tickets', 'dev3-ai-helpdesk' ) }>
					<FormGrid>
						<NumberField label={ __( 'First ticket number', 'dev3-ai-helpdesk' ) } min={ 1 } value={ v.number_start } onChange={ set( 'number_start' ) } help={ __( 'Numbers continue from the highest existing ticket.', 'dev3-ai-helpdesk' ) } />
						<NumberField label={ __( 'Auto-close resolved tickets after (days)', 'dev3-ai-helpdesk' ) } min={ 0 } max={ 365 } value={ v.auto_close_days } onChange={ set( 'auto_close_days' ) } help={ __( '0 turns auto-close off.', 'dev3-ai-helpdesk' ) } />
						<Select label={ __( 'Auto-assign new tickets', 'dev3-ai-helpdesk' ) } value={ v.auto_assign } onChange={ set( 'auto_assign' ) } options={ [ { value: 'none', label: __( 'No, leave unassigned', 'dev3-ai-helpdesk' ) }, { value: 'round_robin', label: __( 'Round-robin within the department', 'dev3-ai-helpdesk' ) } ] } />
					</FormGrid>
					<Toggle label={ __( 'Guests can open tickets (no account needed)', 'dev3-ai-helpdesk' ) } checked={ !! v.guest_tickets } onChange={ set( 'guest_tickets' ) } />
					<Toggle label={ __( 'Require login to contact support', 'dev3-ai-helpdesk' ) } checked={ !! v.require_login } onChange={ set( 'require_login' ) } />
					<Toggle label={ __( 'Customers choose a priority', 'dev3-ai-helpdesk' ) } checked={ !! v.customer_priority } onChange={ set( 'customer_priority' ) } />
					<Toggle label={ __( 'Reopen resolved or closed tickets when the customer replies', 'dev3-ai-helpdesk' ) } checked={ !! v.reopen_on_reply } onChange={ set( 'reopen_on_reply' ) } />
					<Toggle label={ __( 'Agents see every ticket (not only their departments)', 'dev3-ai-helpdesk' ) } checked={ !! v.agents_see_all } onChange={ set( 'agents_see_all' ) } />
					<Toggle label={ __( 'Ask for a satisfaction rating when a ticket is resolved', 'dev3-ai-helpdesk' ) } checked={ !! v.csat_enabled } onChange={ set( 'csat_enabled' ) } />
					{ config.woo && <Toggle label={ __( 'Show the WooCommerce order panel and the order-number field', 'dev3-ai-helpdesk' ) } checked={ !! v.woo_panel } onChange={ ( on ) => { set( 'woo_panel' )( on ); set( 'woo_order_field' )( on ); } } /> }
				</Card>
				<Card title={ __( 'Attachments', 'dev3-ai-helpdesk' ) } description={ __( 'Stored in a private folder and only served through signed, expiring links.', 'dev3-ai-helpdesk' ) }>
					<Toggle label={ __( 'Allow attachments', 'dev3-ai-helpdesk' ) } checked={ !! v.allow_attachments } onChange={ set( 'allow_attachments' ) } />
					<FormGrid>
						<NumberField label={ __( 'Files per message', 'dev3-ai-helpdesk' ) } min={ 0 } max={ 10 } value={ v.max_files } onChange={ set( 'max_files' ) } />
						<NumberField label={ __( 'Max size per file (MB)', 'dev3-ai-helpdesk' ) } min={ 1 } max={ 50 } value={ v.max_file_mb } onChange={ set( 'max_file_mb' ) } />
					</FormGrid>
				</Card>
				<Card title={ __( 'Portal and help centre', 'dev3-ai-helpdesk' ) }>
					<FormGrid>
						<ColorField label={ __( 'Accent colour', 'dev3-ai-helpdesk' ) } value={ v.accent_color } onChange={ set( 'accent_color' ) } />
						<TextField label={ __( 'Help article URL base', 'dev3-ai-helpdesk' ) } value={ v.kb_slug } onChange={ set( 'kb_slug' ) } prefix="/" help={ __( 'Articles live at /help/article/…; re-save Settings → Permalinks after changing it.', 'dev3-ai-helpdesk' ) } />
					</FormGrid>
					<Textarea label={ __( 'Text above the ticket form', 'dev3-ai-helpdesk' ) } rows={ 2 } value={ v.portal_intro } onChange={ set( 'portal_intro' ) } />
					<Textarea label={ __( 'Blocked senders', 'dev3-ai-helpdesk' ) } rows={ 3 } value={ v.block_list } onChange={ set( 'block_list' ) } help={ __( 'One email or @domain per line. Their forms and emails are refused.', 'dev3-ai-helpdesk' ) } />
				</Card>
				<Card title={ __( 'Data', 'dev3-ai-helpdesk' ) }>
					<Toggle label={ __( 'Delete all helpdesk data when the plugin is deleted', 'dev3-ai-helpdesk' ) } checked={ !! v.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } help={ __( 'Tickets, replies, attachments, help articles and settings.', 'dev3-ai-helpdesk' ) } />
				</Card>
			</Stack>
		),
		departments: (
			<Stack gap={ 16 }>
				<Card title={ __( 'Departments', 'dev3-ai-helpdesk' ) } description={ __( 'Teams that own tickets. A department email receives new-ticket alerts (otherwise its agents do).', 'dev3-ai-helpdesk' ) } footer={ <Button icon="plus" onClick={ () => set( 'departments' )( [ ...depts, { id: '', name: '', email: '', agents: [] } ] ) }>{ __( 'Add department', 'dev3-ai-helpdesk' ) }</Button> }>
					{ depts.map( ( d, i ) => (
						<div className="dev3hd-rowedit" key={ i }>
							<TextField label={ __( 'Name', 'dev3-ai-helpdesk' ) } value={ d.name } onChange={ ( x ) => updDept( i, 'name', x ) } />
							<TextField label={ __( 'Notification email', 'dev3-ai-helpdesk' ) } type="email" value={ d.email } onChange={ ( x ) => updDept( i, 'email', x ) } />
							<IconButton icon="trash" label={ sprintf( /* translators: %s: name. */ __( 'Remove %s', 'dev3-ai-helpdesk' ), d.name || __( 'department', 'dev3-ai-helpdesk' ) ) } onClick={ () => set( 'departments' )( depts.filter( ( _, j ) => j !== i ) ) } />
						</div>
					) ) }
				</Card>
				<Card title={ __( 'Categories', 'dev3-ai-helpdesk' ) } description={ __( 'Topics customers pick on the form (and DEV3 AI triage chooses from). Each routes to a department.', 'dev3-ai-helpdesk' ) } footer={ <Button icon="plus" onClick={ () => set( 'categories' )( [ ...cats, { id: '', name: '', department: depts[ 0 ] ? depts[ 0 ].id : '' } ] ) }>{ __( 'Add category', 'dev3-ai-helpdesk' ) }</Button> }>
					{ cats.map( ( c, i ) => (
						<div className="dev3hd-rowedit" key={ i }>
							<TextField label={ __( 'Name', 'dev3-ai-helpdesk' ) } value={ c.name } onChange={ ( x ) => updCat( i, 'name', x ) } />
							<Select label={ __( 'Department', 'dev3-ai-helpdesk' ) } value={ c.department } onChange={ ( x ) => updCat( i, 'department', x ) } options={ depts.filter( ( d ) => d.id ).map( ( d ) => ( { value: d.id, label: d.name } ) ) } />
							<IconButton icon="trash" label={ sprintf( /* translators: %s: name. */ __( 'Remove %s', 'dev3-ai-helpdesk' ), c.name || __( 'category', 'dev3-ai-helpdesk' ) ) } onClick={ () => set( 'categories' )( cats.filter( ( _, j ) => j !== i ) ) } />
						</div>
					) ) }
				</Card>
			</Stack>
		),
		agents: <Agents departments={ depts.filter( ( d ) => d.id ) } />,
		sla: (
			<Stack gap={ 16 }>
				<Card title={ __( 'SLA targets', 'dev3-ai-helpdesk' ) } description={ __( 'Hours until the first reply and until resolution, per priority. The clock pauses while a ticket waits on the customer. 0 = no target.', 'dev3-ai-helpdesk' ) }>
					<Toggle label={ __( 'Track SLA targets', 'dev3-ai-helpdesk' ) } checked={ !! v.sla_enabled } onChange={ set( 'sla_enabled' ) } />
					<div className="dev3hd-sla-grid">
						{ [ 'urgent', 'high', 'normal', 'low' ].map( ( p ) => (
							<div key={ p } className="dev3hd-sla-row">
								<strong>{ priorityLabel( p ) }</strong>
								<NumberField label={ __( 'First response (hours)', 'dev3-ai-helpdesk' ) } min={ 0 } step={ 0.5 } value={ ( v.sla[ p ] || {} ).first } onChange={ ( x ) => setNested( 'sla', p, { ...( v.sla[ p ] || {} ), first: x } ) } />
								<NumberField label={ __( 'Resolution (hours)', 'dev3-ai-helpdesk' ) } min={ 0 } step={ 0.5 } value={ ( v.sla[ p ] || {} ).resolve } onChange={ ( x ) => setNested( 'sla', p, { ...( v.sla[ p ] || {} ), resolve: x } ) } />
							</div>
						) ) }
					</div>
				</Card>
				<Card title={ __( 'Business hours', 'dev3-ai-helpdesk' ) } description={ __( 'Count only working hours (site time zone) towards SLA targets.', 'dev3-ai-helpdesk' ) }>
					<Toggle label={ __( 'Use business hours', 'dev3-ai-helpdesk' ) } checked={ !! v.business_hours } onChange={ set( 'business_hours' ) } />
					<fieldset className="dev3hd-fieldset">
						<legend>{ __( 'Working days', 'dev3-ai-helpdesk' ) }</legend>
						<div className="dev3hd-checks">
							{ DAYS.map( ( d ) => (
								<Checkbox key={ d.value } label={ d.label } checked={ ( v.bh_days || [] ).includes( d.value ) } onChange={ ( on ) => set( 'bh_days' )( on ? [ ...( v.bh_days || [] ), d.value ] : ( v.bh_days || [] ).filter( ( x ) => x !== d.value ) ) } />
							) ) }
						</div>
					</fieldset>
					<FormGrid>
						<NumberField label={ __( 'Opens at (hour)', 'dev3-ai-helpdesk' ) } min={ 0 } max={ 23 } value={ v.bh_start } onChange={ set( 'bh_start' ) } />
						<NumberField label={ __( 'Closes at (hour)', 'dev3-ai-helpdesk' ) } min={ 1 } max={ 24 } value={ v.bh_end } onChange={ set( 'bh_end' ) } />
					</FormGrid>
				</Card>
			</Stack>
		),
		emails: (
			<Card title={ __( 'Notifications', 'dev3-ai-helpdesk' ) } description={ __( 'Customer emails carry the site name only (no plugin branding) and a [#number] tag so replies thread correctly.', 'dev3-ai-helpdesk' ) }>
				<FormGrid>
					<TextField label={ __( 'From name', 'dev3-ai-helpdesk' ) } value={ v.from_name } onChange={ set( 'from_name' ) } placeholder={ __( 'Site name', 'dev3-ai-helpdesk' ) } />
					<TextField label={ __( 'From email', 'dev3-ai-helpdesk' ) } type="email" value={ v.from_email } onChange={ set( 'from_email' ) } help={ __( 'Defaults to the IMAP user, then the admin email.', 'dev3-ai-helpdesk' ) } />
				</FormGrid>
				{ Object.keys( NOTIFY ).map( ( k ) => (
					<Toggle key={ k } label={ NOTIFY[ k ] } checked={ !! ( v.notify || {} )[ k ] } onChange={ ( on ) => setNested( 'notify', k, on ) } />
				) ) }
			</Card>
		),
		mail: <MailSection v={ v } set={ set } />,
		ai: (
			<Stack gap={ 16 }>
				<Card tone="ai" title={ __( 'DEV3 AI automation', 'dev3-ai-helpdesk' ) } description={ __( 'Runs in the background for every new ticket. Results wait on the ticket for an agent unless you allow them to apply.', 'dev3-ai-helpdesk' ) }>
					<Toggle label={ __( 'Triage new tickets (category, priority, sentiment)', 'dev3-ai-helpdesk' ) } checked={ !! v.ai_auto_triage } onChange={ set( 'ai_auto_triage' ) } />
					<Toggle label={ __( 'Apply triage automatically', 'dev3-ai-helpdesk' ) } help={ __( 'Off: agents review and click “Apply triage”. On: category, priority and department are set right away and logged.', 'dev3-ai-helpdesk' ) } checked={ !! v.ai_auto_apply_triage } onChange={ set( 'ai_auto_apply_triage' ) } disabled={ ! v.ai_auto_triage } />
					<Toggle label={ __( 'Prepare a reply draft from the knowledge base', 'dev3-ai-helpdesk' ) } checked={ !! v.ai_auto_draft } onChange={ set( 'ai_auto_draft' ) } />
					<NumberField label={ __( 'Summarise threads with at least this many messages', 'dev3-ai-helpdesk' ) } min={ 2 } max={ 100 } value={ v.ai_summary_min } onChange={ set( 'ai_summary_min' ) } />
					<Toggle label={ __( 'Semantic search in the help centre and ticket form', 'dev3-ai-helpdesk' ) } help={ __( 'Uses embeddings (rate-limited, cached, keyword fallback). Respects the storefront AI switch in API Config.', 'dev3-ai-helpdesk' ) } checked={ !! v.ai_public_search } onChange={ set( 'ai_public_search' ) } />
				</Card>
				<Card title={ __( 'Auto-send (off by default)', 'dev3-ai-helpdesk' ) } description={ __( 'Lets DEV3 AI answer simple questions on its own. It only sends when the draft cites at least one help article, the confidence is above the threshold, it does not ask for a human, and no agent has replied yet.', 'dev3-ai-helpdesk' ) }>
					{ v.ai_auto_send && <Notice tone="warning">{ __( 'Customers will receive AI-written replies without review. Check your help articles are accurate.', 'dev3-ai-helpdesk' ) }</Notice> }
					<Toggle label={ __( 'Send confident drafts automatically', 'dev3-ai-helpdesk' ) } checked={ !! v.ai_auto_send } onChange={ set( 'ai_auto_send' ) } />
					<FormGrid>
						<NumberField label={ __( 'Minimum confidence (0.5–1)', 'dev3-ai-helpdesk' ) } min={ 0.5 } max={ 1 } step={ 0.05 } value={ v.ai_send_threshold } onChange={ set( 'ai_send_threshold' ) } />
						<TextField label={ __( 'Name on automatic replies', 'dev3-ai-helpdesk' ) } value={ v.ai_agent_name } onChange={ set( 'ai_agent_name' ) } />
					</FormGrid>
				</Card>
			</Stack>
		),
	};

	return (
		<>
			<PageHeader title={ __( 'Settings', 'dev3-ai-helpdesk' ) } description={ __( 'How tickets arrive, who handles them, and what DEV3 AI may do.', 'dev3-ai-helpdesk' ) } />
			<Tabs label={ __( 'Settings sections', 'dev3-ai-helpdesk' ) } value={ active } onChange={ ( id ) => navigate( `settings/${ id }` ) } tabs={ SECTIONS } />
			<div className="dev3hd-settings">{ body[ active ] }</div>
			{ active !== 'agents' && <SaveBar dirty={ dirty } busy={ busy } onSave={ submit } /> }
		</>
	);
}
