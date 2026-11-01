/**
 * Front end (vanilla JS): ticket form validation + multipart submit with honeypot/time trap, article
 * suggestions, ticket view (no markup injection), reply, close, rating, lost link, help-centre search, votes.
 */
import { mount } from '../../src/frontend/portal';
import { drawResults, mountHelp, mountVote } from '../../src/frontend/help';
import { restUrl } from '../../src/frontend/api';
import { sprintf } from '../../src/frontend/dom';

const STRINGS = new Proxy( { attachHint: 'Up to %1$d files, %2$d MB each.', createdTitle: 'Ticket #%s is open', submit: 'Send request', sendReply: 'Send reply', required: 'Please fill in this field.', invalidEmail: 'Enter a valid email address.' }, { get: ( t, k ) => ( k in t ? t[ k ] : String( k ) ) } );
const base = ( extra = {} ) => ( {
	restUrl: '/wp-json/dev3hd/v1/',
	nonce: '',
	loggedIn: false,
	user: null,
	guest: true,
	loginUrl: '/login/',
	categories: [ { value: 'billing', label: 'Billing' } ],
	priorities: [],
	orderField: true,
	attachments: { enabled: true, max: 2, mb: 5, accept: '.pdf' },
	csat: true,
	strings: STRINGS,
	ratingLabels: { 1: 'Not good', 2: 'Okay', 3: 'Great' },
	...extra,
} );
const TICKET = {
	number: 1001,
	key: 'k'.repeat( 32 ),
	subject: 'Refund',
	status: 'pending',
	status_label: 'Waiting on customer',
	category: 'Billing',
	created_at: '2026-10-20T10:00:00Z',
	replies: [
		{ id: 1, mine: true, name: 'Grace', body: '<img src=x onerror=alert(1)>', attachments: [], created_at: '2026-10-20T10:00:00Z' },
		{ id: 2, mine: false, name: 'Ada', body: 'Refund sent.', attachments: [ { name: 'proof.pdf', url: 'https://shop.test/?dev3hd_file=a', image: false } ], created_at: '2026-10-20T11:00:00Z' },
	],
	can_reply: true,
	can_close: true,
	can_rate: false,
	rating: 0,
};

const flush = () => new Promise( ( r ) => setTimeout( r, 0 ) );
const fakeClient = ( handlers, calls ) => ( {
	get: jest.fn( ( path, params ) => {
		calls.push( { method: 'GET', path, params } );
		const h = handlers[ `GET ${ path }` ];
		return h ? Promise.resolve( h( params ) ) : Promise.reject( new Error( 'nope' ) );
	} ),
	post: jest.fn( ( path, data ) => {
		calls.push( { method: 'POST', path, data } );
		const h = handlers[ `POST ${ path }` ];
		if ( ! h ) {
			return Promise.reject( new Error( 'nope' ) );
		}
		try {
			return Promise.resolve( h( data ) );
		} catch ( e ) {
			return Promise.reject( e );
		}
	} ),
} );
const setup = async ( config, handlers, href = 'https://shop.test/support/', viewName = 'portal' ) => {
	const root = document.createElement( 'div' );
	root.setAttribute( 'data-view', viewName );
	document.body.appendChild( root );
	const calls = [];
	const ctrl = mount( root, config, { client: fakeClient( handlers, calls ), location: { href }, history: { replaceState: jest.fn() }, confirm: () => true } );
	await ctrl.ready;
	await flush();
	return { root, calls };
};
const submit = async ( form ) => {
	form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );
	await flush();
	await flush();
};
const type = ( el, value ) => {
	el.value = value;
	el.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
};

afterEach( () => {
	document.body.innerHTML = '';
	jest.useRealTimers();
} );

describe( 'helpers', () => {
	it( 'builds REST urls and formats strings', () => {
		expect( restUrl( '/wp-json/dev3hd/v1/', 'kb/search', { q: 'a b', limit: 3 } ) ).toBe( '/wp-json/dev3hd/v1/kb/search?q=a%20b&limit=3' );
		expect( restUrl( '/index.php?rest_route=/dev3hd/v1/', 'kb/search', { q: 'x' } ) ).toBe( '/index.php?rest_route=/dev3hd/v1/kb/search&q=x' );
		expect( sprintf( 'Up to %1$d files, %2$d MB', 3, 5 ) ).toBe( 'Up to 3 files, 5 MB' );
	} );
} );

describe( 'ticket form', () => {
	it( 'validates required fields before sending', async () => {
		const { root, calls } = await setup( base(), {} );
		await submit( root.querySelector( 'form' ) );
		expect( calls.filter( ( c ) => c.method === 'POST' ) ).toHaveLength( 0 );
		const errors = Array.from( root.querySelectorAll( '.dev3hd-p-error' ) ).filter( ( e ) => ! e.hidden );
		expect( errors.length ).toBeGreaterThanOrEqual( 3 );
		expect( root.querySelector( '[aria-invalid="true"]' ) ).not.toBeNull();
		expect( root.textContent ).toContain( 'lostLink' );
	} );

	it( 'submits multipart data with the honeypot and fill time, then shows the confirmation', async () => {
		const { root, calls } = await setup( base(), { 'POST portal/tickets': () => ( { number: 1001, key: 'k'.repeat( 32 ) } ) } );
		const inputs = root.querySelectorAll( 'input.dev3hd-p-input' );
		inputs[ 0 ].value = 'Grace Hopper';
		inputs[ 1 ].value = 'grace@example.com';
		inputs[ 2 ].value = '1234';
		inputs[ 3 ].value = 'Refund missing';
		root.querySelector( 'textarea' ).value = 'Where is it?';
		root.querySelector( 'select' ).value = 'billing';
		await submit( root.querySelector( 'form' ) );
		const post = calls.find( ( c ) => c.path === 'portal/tickets' );
		expect( post.data ).toBeInstanceOf( window.FormData );
		expect( post.data.get( 'email' ) ).toBe( 'grace@example.com' );
		expect( post.data.get( 'order' ) ).toBe( '1234' );
		expect( post.data.get( 'category' ) ).toBe( 'billing' );
		expect( post.data.get( 'website' ) ).toBe( '' );
		expect( parseInt( post.data.get( 'elapsed' ), 10 ) ).toBeGreaterThanOrEqual( 0 );
		expect( post.data.get( 'source' ) ).toBe( 'portal' );
		expect( root.textContent ).toContain( 'Ticket #1001 is open' );
	} );

	it( 'shows server errors and keeps the form', async () => {
		const { root } = await setup( base(), {
			'POST portal/tickets': () => {
				throw new Error( 'We could not find that order for your email address.' );
			},
		} );
		const inputs = root.querySelectorAll( 'input.dev3hd-p-input' );
		inputs[ 0 ].value = 'G';
		inputs[ 1 ].value = 'g@example.com';
		inputs[ 3 ].value = 'S';
		root.querySelector( 'textarea' ).value = 'M';
		await submit( root.querySelector( 'form' ) );
		expect( root.querySelector( '.dev3hd-p-alert.is-bad' ).textContent ).toContain( 'could not find that order' );
		expect( root.querySelector( 'button[type="submit"]' ).disabled ).toBe( false );
	} );

	it( 'suggests help articles while typing', async () => {
		jest.useFakeTimers();
		const root = document.createElement( 'div' );
		document.body.appendChild( root );
		const calls = [];
		mount( root, base(), { client: fakeClient( { 'GET kb/search': () => ( { results: [ { title: 'Refund times', url: '/help/a', excerpt: 'Five days.' } ] } ) }, calls ), location: { href: 'https://shop.test/support/' }, history: { replaceState: jest.fn() } } );
		const subject = root.querySelectorAll( 'input.dev3hd-p-input' )[ 3 ];
		type( subject, 'Where is my refund' );
		jest.advanceTimersByTime( 700 );
		jest.useRealTimers();
		await flush();
		expect( calls[ 0 ].params.q ).toContain( 'refund' );
		const box = root.querySelector( '.dev3hd-p-suggest' );
		expect( box.hidden ).toBe( false );
		expect( box.querySelector( 'a' ).getAttribute( 'href' ) ).toBe( '/help/a' );
	} );

	it( 'asks guests to log in when guest tickets are off', async () => {
		const { root } = await setup( base( { guest: false } ), {} );
		expect( root.querySelector( 'form' ) ).toBeNull();
		expect( root.querySelector( 'a' ).getAttribute( 'href' ) ).toBe( '/login/' );
	} );
} );

describe( 'ticket view', () => {
	it( 'renders the thread as text, replies with files and closes', async () => {
		const { root, calls } = await setup(
			base(),
			{
				'GET portal/tickets/1001': () => TICKET,
				'POST portal/tickets/1001/reply': () => ( { ...TICKET, replies: [ ...TICKET.replies, { id: 3, mine: true, name: 'Grace', body: 'Thanks!', attachments: [], created_at: '' } ] } ),
				'POST portal/tickets/1001/close': () => ( { ...TICKET, status: 'resolved', status_label: 'Resolved', can_close: false, can_rate: true } ),
			},
			`https://shop.test/support/?ticket=1001&key=${ 'k'.repeat( 32 ) }`
		);
		expect( calls[ 0 ].params.key ).toBe( 'k'.repeat( 32 ) );
		expect( root.querySelector( '.dev3hd-p-msg img' ) ).toBeNull();
		expect( root.querySelector( '.dev3hd-p-msg.is-mine .dev3hd-p-pre' ).textContent ).toBe( '<img src=x onerror=alert(1)>' );
		expect( root.querySelector( '.dev3hd-p-files.is-links a' ).textContent ).toContain( 'proof.pdf' );
		const form = root.querySelectorAll( 'form' )[ 0 ];
		form.querySelector( 'textarea' ).value = 'Thanks!';
		await submit( form );
		const reply = calls.find( ( c ) => c.path === 'portal/tickets/1001/reply' );
		expect( reply.data.get( 'key' ) ).toBe( 'k'.repeat( 32 ) );
		expect( reply.data.get( 'body' ) ).toBe( 'Thanks!' );
		expect( root.textContent ).toContain( 'Thanks!' );
		const close = Array.from( root.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent.includes( 'closeTicket' ) );
		close.click();
		await flush();
		await flush();
		expect( calls.find( ( c ) => c.path === 'portal/tickets/1001/close' ).data ).toEqual( { key: 'k'.repeat( 32 ) } );
		expect( root.querySelector( '.dev3hd-p-rating' ) ).not.toBeNull();
	} );

	it( 'preselects the rating from the email link and sends it', async () => {
		const done = { ...TICKET, status: 'resolved', status_label: 'Resolved', can_close: false, can_rate: true };
		const { root, calls } = await setup( base(), { 'GET portal/tickets/1001': () => done, 'POST portal/tickets/1001/rate': () => ( { ...done, can_rate: false, rating: 3 } ) }, 'https://shop.test/support/?ticket=1001&key=abc&rate=3' );
		const checked = root.querySelector( '.dev3hd-p-rate input:checked' );
		expect( checked.value ).toBe( '3' );
		await submit( root.querySelector( '.dev3hd-p-rating form' ) );
		expect( calls.find( ( c ) => c.path === 'portal/tickets/1001/rate' ).data ).toEqual( { key: 'abc', rating: 3, comment: '' } );
		expect( root.textContent ).toContain( 'rated' );
		expect( root.textContent ).toContain( 'Great' );
	} );

	it( 'shows not found and the lost-link form for a bad key', async () => {
		const { root, calls } = await setup( base(), { 'POST portal/resend': () => ( { sent: true } ) }, 'https://shop.test/support/?ticket=1001&key=bad' );
		expect( root.textContent ).toContain( 'notFound' );
		const form = root.querySelector( '.dev3hd-p-inline' );
		form.querySelector( 'input' ).value = 'grace@example.com';
		await submit( form );
		expect( calls.find( ( c ) => c.path === 'portal/resend' ).data ).toEqual( { email: 'grace@example.com' } );
		expect( root.textContent ).toContain( 'linksSent' );
	} );

	it( 'lists a logged-in customer’s tickets', async () => {
		const { root } = await setup( base( { loggedIn: true, user: { name: 'Grace', email: 'g@example.com' } } ), { 'GET portal/tickets': () => [ { number: 1001, key: 'k', subject: 'Refund', status: 'pending', status_label: 'Waiting on customer', updated_at: '', awaiting: true } ] } );
		expect( root.querySelector( '.dev3hd-p-row' ).textContent ).toContain( 'Refund' );
		expect( root.textContent ).toContain( 'awaiting' );
	} );
} );

describe( 'help centre', () => {
	const helpDom = () => {
		const root = document.createElement( 'div' );
		root.innerHTML = '<form class="dev3hd-hc-search"><input class="dev3hd-hc-input" type="search"><button type="submit">Search</button></form><div class="dev3hd-hc-results"></div>';
		document.body.appendChild( root );
		return root;
	};

	it( 'shows instant results as text', async () => {
		const root = helpDom();
		const calls = [];
		const ctrl = mountHelp( root, base(), { client: fakeClient( { 'GET kb/search': () => ( { mode: 'semantic', results: [ { title: '<b>Reset</b> password', url: '/help/reset', excerpt: 'Steps', category: 'Account' } ] } ) }, calls ) } );
		await ctrl.search( 'forgot password' );
		expect( calls[ 0 ].params ).toEqual( { q: 'forgot password', limit: 8 } );
		const link = root.querySelector( '.dev3hd-hc-hits a' );
		expect( link.getAttribute( 'href' ) ).toBe( '/help/reset' );
		expect( root.querySelector( '.dev3hd-hc-hits b' ) ).toBeNull();
		await ctrl.search( 'a' );
		expect( root.querySelector( '.dev3hd-hc-results' ).textContent ).toBe( '' );
		const box = document.createElement( 'div' );
		drawResults( box, [], STRINGS );
		expect( box.textContent ).toBe( 'noResults' );
	} );

	it( 'records an article vote once', async () => {
		const root = document.createElement( 'div' );
		root.setAttribute( 'data-dev3hd-vote', '12' );
		root.innerHTML = '<button class="dev3hd-vote-btn" data-vote="yes">Yes</button><button class="dev3hd-vote-btn" data-vote="no">No</button><span class="dev3hd-vote-msg"></span>';
		document.body.appendChild( root );
		const calls = [];
		mountVote( root, base(), { client: fakeClient( { 'POST kb/vote': () => ( { ok: true } ) }, calls ) } );
		root.querySelector( '[data-vote="yes"]' ).click();
		await flush();
		expect( calls[ 0 ].data ).toEqual( { id: 12, helpful: true } );
		expect( root.querySelector( '[data-vote="no"]' ).disabled ).toBe( true );
		expect( root.querySelector( '.dev3hd-vote-msg' ).textContent ).toBe( 'helpful' );
	} );
} );
