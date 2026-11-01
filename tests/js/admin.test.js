/**
 * Admin screens: dashboard, inbox (filters, bulk), ticket detail (reply + note via FormData, AI draft only
 * fills the composer, triage apply), saved replies, knowledge base, reports, settings + util helpers.
 */
import apiFetch from '@wordpress/api-fetch';
import { createElement } from '@wordpress/element';
import { ToastProvider } from '../../src/core3/ui';
import Dashboard from '../../src/admin/Dashboard';
import Tickets from '../../src/admin/Tickets';
import TicketDetail from '../../src/admin/TicketDetail';
import Canned from '../../src/admin/Canned';
import Knowledge from '../../src/admin/Knowledge';
import Reports from '../../src/admin/Reports';
import SettingsTab from '../../src/admin/SettingsTab';
import { BarList, TrendChart } from '../../src/admin/Charts';
import { BootContext, duration, qs, slaBadge, timeAgo } from '../../src/admin/util';
import { change, click, render } from './render';

Object.assign( window.dev3hdConfig, {
	statuses: { new: 'New', open: 'Open', pending: 'Waiting on customer', on_hold: 'On hold', resolved: 'Resolved', closed: 'Closed' },
	priorities: { low: 'Low', normal: 'Normal', high: 'High', urgent: 'Urgent' },
	channels: { portal: 'Portal', email: 'Email', agent: 'Agent' },
	isManager: true,
	pages: { portal: 'https://shop.test/support/', help: 'https://shop.test/help-centre/' },
	kbCatsUrl: '/wp-admin/edit-tags.php',
	newKbUrl: '/wp-admin/post-new.php',
} );

const BOOT = {
	me: { id: 1, name: 'Ada Admin', manager: true, departments: [], can_kb: true },
	departments: [ { id: 'support', name: 'Customer support' }, { id: 'billing', name: 'Billing' } ],
	categories: [ { id: 'billing', name: 'Billing & refunds', department: 'billing' }, { id: 'general', name: 'General question', department: 'support' } ],
	agents: [ { id: 1, name: 'Ada Admin', avatar: '', departments: [] }, { id: 2, name: 'Bo Agent', avatar: '', departments: [ 'support' ] } ],
	canned: [ { id: 7, title: 'Refund policy', body: 'Hi {{customer_first}}', department: '' } ],
	ai: { enabled: true, public: true, key_set: true, cap_reached: false },
	options: { sla: true, csat: true, attachments: true, max_files: 3, max_file_mb: 5, woo: false },
	counts: { active: 3, new: 1 },
	refresh: jest.fn(),
};

const ROW = {
	id: 11,
	number: 1001,
	subject: 'Refund not received',
	status: 'open',
	priority: 'high',
	department: 'billing',
	department_name: 'Billing',
	category: 'billing',
	category_name: 'Billing & refunds',
	agent: null,
	name: 'Grace Hopper',
	email: 'grace@example.com',
	channel: 'email',
	tags: [ 'refund' ],
	sentiment: 'negative',
	sla: { first: { due: '2030-01-01 10:00:00', met: null, overdue: false, left: 5400 }, resolve: null, paused: false },
	overdue: false,
	last_reply_by: 'customer',
	ai_state: 'done',
	has_triage: true,
	has_draft: false,
	rating: 0,
	created_at: '2026-10-20T10:00:00Z',
	updated_at: '2026-10-24T10:00:00Z',
};

const TICKET = {
	...ROW,
	replies: [
		{ id: 1, type: 'reply', author: 'customer', name: 'Grace Hopper', email: 'grace@example.com', body: '<img src=x onerror=alert(1)> Where is my refund?', attachments: [ { name: 'receipt.pdf', size: 2048, mime: 'application/pdf', image: false, url: 'https://shop.test/?dev3hd_file=x' } ], via: 'email', created_at: '2026-10-20T10:00:00Z' },
		{ id: 2, type: 'event', author: 'system', name: '', body: 'Ticket received via Email.', attachments: [], created_at: '2026-10-20T10:00:01Z' },
		{ id: 3, type: 'note', author: 'agent', name: 'Bo Agent', body: 'Checked Stripe: pending.', attachments: [], created_at: '2026-10-20T11:00:00Z' },
	],
	messages: 1,
	ai: {
		triage: { category: 'billing', priority: 'high', sentiment: 'negative', summary: 'Customer waits for a refund.', tags: [ 'refund' ], confidence: 0.9, applied: false, language: 'English' },
		draft: null,
		summary: null,
	},
	summary_stale: false,
	summary_suggest: false,
	rating_comment: '',
	rating_label: '',
	portal_url: 'https://shop.test/support/?ticket=1001&key=k',
	customer: { name: 'Grace Hopper', email: 'grace@example.com', avatar: '', registered: null, user_url: '', tickets: [] },
};

const wrap = ( el, boot = BOOT ) => createElement( BootContext.Provider, { value: boot }, createElement( ToastProvider, null, el ) );
const route = ( handlers ) =>
	apiFetch.mockImplementation( ( opts ) => {
		const path = opts.url.replace( '/wp-json/dev3hd/v1/', '' );
		const method = opts.method || 'GET';
		for ( const [ k, fn ] of Object.entries( handlers ) ) {
			const [ m, p ] = k.split( ' ' );
			if ( m === method && path.split( '?' )[ 0 ] === p ) {
				return Promise.resolve( fn( opts, path ) );
			}
		}
		return new Promise( () => {} );
	} );

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

describe( 'util', () => {
	it( 'formats durations and relative times', () => {
		expect( duration( null ) ).toBe( '—' );
		expect( duration( 90 ) ).toBe( '2 min' );
		expect( duration( 3 * 3600 + 20 * 60 ) ).toBe( '3 h 20 min' );
		expect( duration( 2 * 86400 + 4 * 3600 ) ).toBe( '2 d 4 h' );
		expect( timeAgo( '2026-10-24T10:00:00Z', Date.parse( '2026-10-24T12:30:00Z' ) ) ).toBe( '2 h ago' );
		expect( qs( { a: 1, b: '', c: 'x y' } ) ).toBe( 'a=1&c=x%20y' );
	} );

	it( 'builds SLA badges', () => {
		expect( slaBadge( { first: { met: null, overdue: true, left: -3600 }, resolve: null, paused: false } ).tone ).toBe( 'danger' );
		expect( slaBadge( { first: { met: null, overdue: false, left: 1800 }, resolve: null, paused: false } ).tone ).toBe( 'warning' );
		expect( slaBadge( { first: { met: true }, resolve: { met: null, overdue: false, left: 86400 }, paused: true } ).label ).toBe( 'SLA paused' );
		expect( slaBadge( { first: { met: false }, resolve: null } ).label ).toBe( 'SLA missed' );
	} );
} );

describe( 'charts', () => {
	it( 'renders paired bars with a legend and an accessible table', async () => {
		const view = await render( createElement( TrendChart, { data: [ { date: '2026-10-01', label: 'Oct 1', created: 4, resolved: 2 }, { date: '2026-10-02', label: 'Oct 2', created: 0, resolved: 3 } ] } ) );
		expect( view.$$( '.dev3hd-chart__col' ) ).toHaveLength( 2 );
		expect( view.$( '.dev3hd-chart__col' ).getAttribute( 'aria-label' ) ).toBe( 'Oct 1: 4 Created, 2 Resolved' );
		expect( view.$$( 'table td' ).map( ( td ) => td.textContent ) ).toEqual( [ 'Oct 1', '4', '2', 'Oct 2', '0', '3' ] );
		view.unmount();
		const list = await render( createElement( BarList, { label: 'Cats', rows: [ { key: 'a', label: 'Billing', value: 3 } ] } ) );
		expect( list.getByText( 'Billing' ) ).toBeTruthy();
	} );
} );

describe( 'dashboard', () => {
	it( 'shows KPIs, attention list and the setup checklist', async () => {
		route( {
			'GET stats': () => ( {
				counts: { active: 5, new: 2, unassigned: 1, overdue: 1 },
				period: { first_response: 7200, csat: 80, ratings: 5 },
				resolved7: 4,
				trend: [],
				by_cat: [ { id: 'billing', label: 'Billing & refunds', count: 3 } ],
				attention: [ ROW ],
				setup: [ { id: 'pages', done: true }, { id: 'mail', done: false } ],
			} ),
		} );
		const view = await render( wrap( createElement( Dashboard, { navigate: jest.fn() } ) ) );
		await view.flush();
		expect( view.getByText( '2 h' ) ).toBeTruthy();
		expect( view.getByText( '80%' ) ).toBeTruthy();
		expect( view.$( '.dev3hd-attention__subject' ).textContent ).toContain( 'Refund not received' );
		expect( view.$$( '.dev3hd-checklist li.is-done' ) ).toHaveLength( 1 );
		expect( view.getByText( 'Set up' ) ).toBeTruthy();
	} );
} );

describe( 'inbox', () => {
	it( 'loads tickets with the tab filter, opens a row and runs bulk actions', async () => {
		const calls = [];
		route( {
			'GET tickets': ( o, path ) => {
				calls.push( path );
				return { rows: [ ROW ], total: 1, counts: { active: 1, mine: 0, unassigned: 1, overdue: 0, pending: 0, resolved: 0, closed: 0, all: 1 } };
			},
			'POST tickets/bulk': ( o ) => {
				calls.push( o.data );
				return { updated: 1 };
			},
		} );
		const navigate = jest.fn();
		const view = await render( wrap( createElement( Tickets, { navigate, onChange: jest.fn() } ) ) );
		await view.flush();
		expect( calls[ 0 ] ).toContain( 'status=active' );
		expect( view.getByText( 'Refund not received' ) ).toBeTruthy();
		expect( view.getByText( 'Triage ready' ) ).toBeTruthy();
		await click( view.getByText( 'Unassigned' ).closest( 'button' ) || view.$$( '[role="tab"]' )[ 2 ] );
		await view.flush();
		expect( calls.some( ( c ) => typeof c === 'string' && c.includes( 'view=unassigned' ) ) ).toBe( true );
		await click( view.$( '.dev3hd-rowcheck' ) );
		const select = view.$$( '.dev3hd-bulkbar select' )[ 1 ];
		await change( select, 'resolved' );
		await view.flush();
		expect( calls.find( ( c ) => c && c.action ) ).toEqual( { ids: [ 11 ], action: 'status', value: 'resolved' } );
		await click( view.$( 'tbody tr' ) || view.$( '.dev3hd-ui-table__card' ) );
		expect( navigate ).toHaveBeenCalledWith( 'tickets/11' );
	} );
} );

describe( 'ticket detail', () => {
	it( 'renders the thread safely, sends a reply with FormData and applies triage', async () => {
		let sent = null;
		let applied = false;
		route( {
			'GET tickets/11': () => TICKET,
			'POST tickets/11/replies': ( o ) => {
				sent = o.body;
				return { ...TICKET, replies: [ ...TICKET.replies, { id: 4, type: 'reply', author: 'agent', name: 'Ada Admin', body: 'On it!', attachments: [], created_at: '2026-10-24T10:00:00Z' } ] };
			},
			'POST tickets/11/ai/apply-triage': () => {
				applied = true;
				return { ...TICKET, ai: { ...TICKET.ai, triage: { ...TICKET.ai.triage, applied: true } } };
			},
			'POST canned/7/use': () => ( { text: 'Hi Grace' } ),
		} );
		const view = await render( wrap( createElement( TicketDetail, { id: 11, navigate: jest.fn(), onChange: jest.fn() } ) ) );
		await view.flush();
		expect( view.$( '.dev3hd-msg.is-customer img' ) ).toBeNull();
		expect( view.$( '.dev3hd-msg.is-customer .dev3hd-pre' ).textContent ).toContain( '<img src=x' );
		expect( view.getByText( 'Internal note' ) ).toBeTruthy();
		expect( view.getByText( 'receipt.pdf' ) ).toBeTruthy();

		await click( view.getByText( 'Apply triage' ).closest( 'button' ) );
		await view.flush();
		expect( applied ).toBe( true );
		expect( view.getByText( 'Applied to this ticket.' ) ).toBeTruthy();

		const canned = view.$$( '.dev3hd-composer__tools select' )[ 0 ];
		await change( canned, '7' );
		await view.flush();
		const textarea = view.$( '.dev3hd-composer textarea' );
		expect( textarea.value ).toBe( 'Hi Grace' );
		await change( textarea, 'On it!' );
		await click( view.getByText( 'Send reply' ).closest( 'button' ) );
		await view.flush();
		expect( sent ).toBeInstanceOf( window.FormData );
		expect( sent.get( 'body' ) ).toBe( 'On it!' );
		expect( sent.get( 'type' ) ).toBe( 'reply' );
		expect( sent.get( 'status' ) ).toBe( 'pending' );
		expect( view.getByText( 'On it!' ) ).toBeTruthy();
	} );

	it( 'shows a KB-grounded AI draft that only fills the composer', async () => {
		let replies = 0;
		route( {
			'GET tickets/11': () => TICKET,
			'POST tickets/11/ai/draft': ( o ) => ( { reply: 'Hi Grace, refunds take 5 days.', tone: o.data.tone, sources: [ { id: 5, title: 'Refund times', url: 'https://shop.test/help/article/refunds' } ], confidence: 0.82, needs_human: false, missing_info: '', model: 'gemini-2.5-flash', tokens: 900 } ),
			'POST tickets/11/replies': () => {
				replies++;
				return TICKET;
			},
		} );
		const view = await render( wrap( createElement( TicketDetail, { id: 11, navigate: jest.fn(), onChange: jest.fn() } ) ) );
		await view.flush();
		await click( view.getByText( 'Draft with DEV3 AI' ).closest( 'button' ) );
		await view.flush();
		expect( view.getByText( 'Grounded in these help articles' ) ).toBeTruthy();
		expect( view.$( '.dev3hd-sources a' ).getAttribute( 'href' ) ).toContain( 'refunds' );
		expect( replies ).toBe( 0 );
		await click( view.getByText( 'Use this draft' ).closest( 'button' ) );
		expect( view.$( '.dev3hd-composer textarea' ).value ).toBe( 'Hi Grace, refunds take 5 days.' );
		expect( replies ).toBe( 0 );
	} );

	it( 'adds internal notes without a status', async () => {
		let sent = null;
		route( {
			'GET tickets/11': () => TICKET,
			'POST tickets/11/replies': ( o ) => {
				sent = o.body;
				return TICKET;
			},
		} );
		const view = await render( wrap( createElement( TicketDetail, { id: 11, navigate: jest.fn(), onChange: jest.fn() } ) ) );
		await view.flush();
		await click( view.getByText( 'Internal note', ).closest( 'button' ) || view.$$( '.dev3hd-composer__modes button' )[ 1 ] );
		await change( view.$( '.dev3hd-composer textarea' ), 'Escalated to finance' );
		await click( view.getByText( 'Add note' ).closest( 'button' ) );
		await view.flush();
		expect( sent.get( 'type' ) ).toBe( 'note' );
		expect( sent.get( 'status' ) ).toBeNull();
	} );
} );

describe( 'saved replies', () => {
	it( 'creates a saved reply', async () => {
		let posted = null;
		route( {
			'GET canned': () => [],
			'POST canned': ( o ) => {
				posted = o.data;
				return { id: 1, ...o.data };
			},
		} );
		const onChange = jest.fn();
		const view = await render( wrap( createElement( Canned, { onChange } ) ) );
		await view.flush();
		await click( view.getByText( 'New saved reply' ).closest( 'button' ) );
		const inputs = view.$$( '.dev3hd-ui-modal input, .dev3hd-ui-modal textarea' );
		await change( inputs[ 0 ], 'Shipping times' );
		await change( view.$( '.dev3hd-ui-modal textarea' ), 'We ship in 2 days.' );
		await click( view.getByText( 'Save' ).closest( 'button' ) );
		await view.flush();
		expect( posted ).toEqual( { title: 'Shipping times', body: 'We ship in 2 days.', department: '' } );
		expect( onChange ).toHaveBeenCalled();
	} );
} );

describe( 'knowledge base and reports', () => {
	it( 'lists articles, rebuilds the index and runs the gaps report', async () => {
		let gapsRun = false;
		route( {
			'GET kb/articles': () => [ { id: 5, title: 'Refund times', category: 'Billing', views: 40, helpful: 90, votes: 10, status: 'publish', indexed: false, edit: '/edit', source: 0, modified: '2026-10-20 10:00:00' } ],
			'GET kb/status': () => ( { articles: 1, indexed: 0, stale: 1, enabled: true, job: null } ),
			'GET kb/gaps': () => null,
			'POST kb/reindex': () => ( { job: null, status: { articles: 1, indexed: 1, stale: 0, enabled: true, job: null } } ),
			'POST kb/gaps': () => {
				gapsRun = true;
				return { at: '2026-10-24 10:00:00', gaps: [ { question: 'How do I change my address?', count: 4, examples: [ { id: 11, number: 1001, subject: 'Address' } ], title: 'Change your shipping address' } ] };
			},
		} );
		const view = await render( wrap( createElement( Knowledge, { navigate: jest.fn(), onUsage: jest.fn() } ) ) );
		await view.flush();
		expect( view.getByText( 'Refund times' ) ).toBeTruthy();
		expect( view.getByText( 'Not indexed' ) ).toBeTruthy();
		await click( view.getByText( 'Find gaps with DEV3 AI' ).closest( 'button' ) );
		await view.flush();
		expect( gapsRun ).toBe( true );
		expect( view.getByText( 'How do I change my address?' ) ).toBeTruthy();
	} );

	it( 'renders reports', async () => {
		route( {
			'GET reports': () => ( {
				days: 30,
				totals: { created: 12, resolved: 9, first_response: 3600, resolution_time: 86400, sla_compliance: 92, csat: 75, ratings: 4 },
				trend: [],
				by_agent: [ { id: 2, name: 'Bo Agent', assigned: 8, resolved: 6, first_response: 1800, csat: 100 } ],
				by_category: [ { label: 'Billing', count: 5, resolved: 4 } ],
				by_channel: [ { label: 'Email', count: 7 } ],
				by_priority: [ { label: 'High', count: 3 } ],
			} ),
		} );
		const view = await render( wrap( createElement( Reports ) ) );
		await view.flush();
		expect( view.getByText( '92%' ) ).toBeTruthy();
		expect( view.getByText( 'Bo Agent' ) ).toBeTruthy();
	} );
} );

describe( 'settings', () => {
	const SETTINGS = {
		number_start: 1001,
		auto_close_days: 7,
		auto_assign: 'none',
		guest_tickets: true,
		require_login: false,
		customer_priority: false,
		reopen_on_reply: true,
		agents_see_all: false,
		csat_enabled: true,
		allow_attachments: true,
		max_files: 3,
		max_file_mb: 5,
		accent_color: '#2563eb',
		kb_slug: 'help',
		portal_intro: '',
		block_list: '',
		departments: [ { id: 'support', name: 'Customer support', email: '', agents: [] } ],
		categories: [ { id: 'general', name: 'General question', department: 'support' } ],
		sla_enabled: true,
		sla: { low: { first: 48, resolve: 120 }, normal: { first: 24, resolve: 72 }, high: { first: 8, resolve: 24 }, urgent: { first: 2, resolve: 8 } },
		business_hours: false,
		bh_days: [ 1, 2, 3, 4, 5 ],
		bh_start: 9,
		bh_end: 17,
		notify: { customer_new: true },
		ai_auto_triage: true,
		ai_auto_send: false,
		ai_send_threshold: 0.85,
	};

	it( 'saves the SLA section and warns about auto-send', async () => {
		const save = jest.fn( () => Promise.resolve( SETTINGS ) );
		const view = await render( wrap( createElement( SettingsTab, { settings: SETTINGS, save, section: 'sla', navigate: jest.fn() } ) ) );
		const input = view.$$( '.dev3hd-sla-row input' )[ 0 ];
		await change( input, '1' );
		await click( view.getByText( 'Save settings' ).closest( 'button' ) );
		await view.flush();
		expect( save.mock.calls[ 0 ][ 0 ].sla.urgent.first ).toBe( 1 );
		view.unmount();
		const ai = await render( wrap( createElement( SettingsTab, { settings: { ...SETTINGS, ai_auto_send: true }, save, section: 'ai', navigate: jest.fn() } ) ) );
		expect( ai.getByText( /Customers will receive AI-written replies/ ) ).toBeTruthy();
	} );

	it( 'adds a department', async () => {
		const save = jest.fn( () => Promise.resolve( SETTINGS ) );
		const view = await render( wrap( createElement( SettingsTab, { settings: SETTINGS, save, section: 'departments', navigate: jest.fn() } ) ) );
		await click( view.getByText( 'Add department' ).closest( 'button' ) );
		const names = view.$$( '.dev3hd-ui-card' )[ 0 ].querySelectorAll( '.dev3hd-rowedit input[type="text"]' );
		expect( names ).toHaveLength( 2 );
		await change( names[ 1 ], 'Returns' );
		expect( names[ 1 ].value ).toBe( 'Returns' );
		await click( view.getByText( 'Save settings' ).closest( 'button' ) );
		await view.flush();
		expect( save.mock.calls[ 0 ][ 0 ].departments.map( ( d ) => d.name ) ).toEqual( [ 'Customer support', 'Returns' ] );
	} );
} );
