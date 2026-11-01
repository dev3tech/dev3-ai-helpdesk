/**
 * Support portal (vanilla JS, small bundle): new-ticket form with article suggestions, "My tickets",
 * ticket view with replies, close and rating, and "lost your link". Text is set with textContent only.
 */
import { createClient } from './api';
import { append, h, icon, id, sprintf } from './dom';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Relative date in the visitor's locale.
 *
 * @param {string} iso ISO date.
 * @return {string} Text.
 */
export function shortDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	return isNaN( d ) ? '' : d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
}

/**
 * Mounts the portal.
 *
 * @param {HTMLElement} root   Container.
 * @param {Object}      config window.dev3hdPortal.
 * @param {Object}      deps   { client, location, history, confirm } (tests).
 * @return {Object} { ready: Promise }
 */
export function mount( root, config, deps = {} ) {
	const s = config.strings || {};
	const client = deps.client || createClient( config );
	const loc = deps.location || window.location;
	const hist = deps.history || window.history;
	const confirmFn = deps.confirm || ( ( msg ) => window.confirm( msg ) );
	const view = root.getAttribute( 'data-view' ) || 'portal';
	const url = new URL( loc.href );

	const setUrl = ( params ) => {
		const next = new URL( loc.href );
		[ 'ticket', 'key', 'rate' ].forEach( ( k ) => next.searchParams.delete( k ) );
		Object.keys( params || {} ).forEach( ( k ) => next.searchParams.set( k, params[ k ] ) );
		try {
			hist.replaceState( null, '', next.toString() );
		} catch ( e ) {
			// Ignore (sandboxed iframes).
		}
	};
	const render = ( ...nodes ) => {
		root.textContent = '';
		append( root, nodes );
	};
	const errorText = ( err ) => ( err && err.message && err.message !== 'error' ? err.message : s.error );
	const alertBox = () => h( 'div', { class: 'dev3hd-p-alert', role: 'alert', hidden: true } );
	const showAlert = ( box, text, tone = 'bad' ) => {
		box.textContent = text;
		box.className = `dev3hd-p-alert is-${ tone }`;
		box.hidden = ! text;
	};
	const spinner = () => h( 'div', { class: 'dev3hd-p-card dev3hd-p-loading', 'aria-busy': 'true' }, h( 'span', { class: 'dev3hd-p-spinner', 'aria-hidden': 'true' } ), s.loading );
	const badge = ( status, label ) => h( 'span', { class: `dev3hd-p-status is-${ status }` }, label );

	/**
	 * A labelled field.
	 *
	 * @param {string}      label   Label.
	 * @param {HTMLElement} control Input.
	 * @param {Object}      opts    { hint, required }.
	 * @return {Object} { wrap, control, error }
	 */
	const field = ( label, control, opts = {} ) => {
		const fid = control.id || id( 'f' );
		control.id = fid;
		const err = h( 'p', { class: 'dev3hd-p-error', id: `${ fid }-err`, hidden: true } );
		const hint = opts.hint ? h( 'p', { class: 'dev3hd-p-hint', id: `${ fid }-hint` }, opts.hint ) : null;
		if ( hint ) {
			control.setAttribute( 'aria-describedby', `${ fid }-hint` );
		}
		const wrap = h( 'div', { class: 'dev3hd-p-field' }, h( 'label', { for: fid }, label, opts.required ? h( 'span', { class: 'dev3hd-p-req', 'aria-hidden': 'true' }, ' *' ) : null ), control, hint, err );
		return {
			wrap,
			control,
			setError( text ) {
				err.textContent = text || '';
				err.hidden = ! text;
				if ( text ) {
					control.setAttribute( 'aria-invalid', 'true' );
					control.setAttribute( 'aria-describedby', `${ fid }-err${ hint ? ` ${ fid }-hint` : '' }` );
				} else {
					control.removeAttribute( 'aria-invalid' );
					if ( hint ) {
						control.setAttribute( 'aria-describedby', `${ fid }-hint` );
					} else {
						control.removeAttribute( 'aria-describedby' );
					}
				}
			},
		};
	};

	/**
	 * File picker with a removable list (respects count and size limits).
	 *
	 * @return {Object} { wrap, files() , reset() }
	 */
	const filePicker = () => {
		const att = config.attachments || {};
		let files = [];
		const input = h( 'input', { type: 'file', multiple: true, class: 'dev3hd-p-file', accept: att.accept || '' } );
		const list = h( 'ul', { class: 'dev3hd-p-files' } );
		const msg = h( 'p', { class: 'dev3hd-p-hint', 'aria-live': 'polite' } );
		const draw = () => {
			list.textContent = '';
			files.forEach( ( f, i ) =>
				list.appendChild(
					h(
						'li',
						{},
						icon( 'file' ),
						h( 'span', { class: 'dev3hd-p-fname' }, f.name ),
						h( 'button', { type: 'button', class: 'dev3hd-p-link', 'aria-label': `${ s.remove } ${ f.name }`, onClick: () => {
							files = files.filter( ( _, j ) => j !== i );
							draw();
						} }, s.remove )
					)
				)
			);
		};
		input.addEventListener( 'change', () => {
			msg.textContent = '';
			Array.from( input.files || [] ).forEach( ( f ) => {
				if ( files.length >= ( att.max || 3 ) ) {
					msg.textContent = s.tooMany;
					return;
				}
				if ( f.size > ( att.mb || 5 ) * 1048576 ) {
					msg.textContent = `${ f.name }: ${ s.tooBig }`;
					return;
				}
				files.push( f );
			} );
			input.value = '';
			draw();
		} );
		const label = h( 'label', { class: 'dev3hd-p-btn dev3hd-p-btn--ghost dev3hd-p-attach' }, icon( 'clip' ), s.attach, input );
		const wrap = h( 'div', { class: 'dev3hd-p-field' }, label, h( 'p', { class: 'dev3hd-p-hint' }, sprintf( s.attachHint, att.max || 3, att.mb || 5 ) ), list, msg );
		return {
			wrap: att.enabled && ( att.max || 0 ) > 0 ? wrap : null,
			files: () => files,
			reset: () => {
				files = [];
				draw();
			},
		};
	};

	/* ---------------------------------------------------------------- Form */
	const showForm = ( opts = {} ) => {
		const started = Date.now();
		const box = alertBox();
		const f = {};
		if ( ! config.loggedIn ) {
			f.name = field( s.name, h( 'input', { type: 'text', class: 'dev3hd-p-input', autocomplete: 'name', maxlength: 120 } ), { required: true } );
			f.email = field( s.email, h( 'input', { type: 'email', class: 'dev3hd-p-input', autocomplete: 'email', inputmode: 'email', maxlength: 190 } ), { required: true } );
		}
		if ( ( config.categories || [] ).length ) {
			f.category = field( s.category, h( 'select', { class: 'dev3hd-p-select' }, h( 'option', { value: '' }, s.chooseCategory ), ( config.categories || [] ).map( ( c ) => h( 'option', { value: c.value }, c.label ) ) ) );
		}
		if ( ( config.priorities || [] ).length ) {
			f.priority = field( s.priority, h( 'select', { class: 'dev3hd-p-select' }, config.priorities.map( ( p ) => h( 'option', { value: p.value, selected: p.value === 'normal' }, p.label ) ) ) );
		}
		if ( config.orderField ) {
			f.order = field( s.order, h( 'input', { type: 'text', class: 'dev3hd-p-input', inputmode: 'numeric', maxlength: 30 } ) );
		}
		f.subject = field( s.subject, h( 'input', { type: 'text', class: 'dev3hd-p-input', maxlength: 190 } ), { required: true } );
		f.message = field( s.message, h( 'textarea', { class: 'dev3hd-p-textarea', rows: 6, maxlength: 20000 } ), { required: true, hint: s.messageHint } );
		const picker = filePicker();
		const honey = h( 'div', { class: 'dev3hd-p-hp', 'aria-hidden': 'true' }, h( 'label', {}, 'Website', h( 'input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' } ) ) );
		const suggest = h( 'div', { class: 'dev3hd-p-suggest', 'aria-live': 'polite', hidden: true } );
		let timer = null;
		let lastQ = '';
		const lookup = () => {
			const q = `${ f.subject.control.value } ${ f.message.control.value.slice( 0, 200 ) }`.trim();
			if ( q.length < 8 || q === lastQ ) {
				return;
			}
			lastQ = q;
			client
				.get( 'kb/search', { q, limit: 3 } )
				.then( ( res ) => {
					const rows = ( res && res.results ) || [];
					suggest.textContent = '';
					suggest.hidden = ! rows.length;
					if ( rows.length ) {
						append( suggest, [
							h( 'p', { class: 'dev3hd-p-suggest-title' }, icon( 'book' ), s.suggestTitle ),
							h( 'ul', {}, rows.map( ( r ) => h( 'li', {}, h( 'a', { href: r.url, target: '_blank', rel: 'noopener' }, r.title ), r.excerpt ? h( 'span', { class: 'dev3hd-p-muted' }, r.excerpt ) : null ) ) ),
						] );
					}
				} )
				.catch( () => {} );
		};
		const queue = () => {
			window.clearTimeout( timer );
			timer = window.setTimeout( lookup, 600 );
		};
		f.subject.control.addEventListener( 'input', queue );
		f.message.control.addEventListener( 'input', queue );

		const submit = h( 'button', { type: 'submit', class: 'dev3hd-p-btn dev3hd-p-btn--primary' }, s.submit );
		const form = h(
			'form',
			{ class: 'dev3hd-p-form', novalidate: true },
			config.loggedIn && config.user ? h( 'p', { class: 'dev3hd-p-muted' }, `${ config.user.name } · ${ config.user.email }` ) : null,
			f.name || f.email ? h( 'div', { class: 'dev3hd-p-grid' }, f.name ? f.name.wrap : null, f.email ? f.email.wrap : null ) : null,
			f.category || f.priority || f.order ? h( 'div', { class: 'dev3hd-p-grid' }, f.category ? f.category.wrap : null, f.priority ? f.priority.wrap : null, f.order ? f.order.wrap : null ) : null,
			f.subject.wrap,
			suggest,
			f.message.wrap,
			picker.wrap,
			honey,
			box,
			h( 'div', { class: 'dev3hd-p-actions' }, submit )
		);
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			let first = null;
			const need = ( fld, ok, msg ) => {
				fld.setError( ok ? '' : msg );
				if ( ! ok && ! first ) {
					first = fld.control;
				}
			};
			if ( f.name ) {
				need( f.name, f.name.control.value.trim() !== '', s.required );
			}
			if ( f.email ) {
				need( f.email, EMAIL_RE.test( f.email.control.value.trim() ), s.invalidEmail );
			}
			need( f.subject, f.subject.control.value.trim() !== '', s.required );
			need( f.message, f.message.control.value.trim() !== '', s.required );
			if ( first ) {
				first.focus();
				return;
			}
			const data = new window.FormData();
			if ( f.name ) {
				data.append( 'name', f.name.control.value.trim() );
				data.append( 'email', f.email.control.value.trim() );
			}
			data.append( 'subject', f.subject.control.value.trim() );
			data.append( 'message', f.message.control.value );
			[ 'category', 'priority', 'order' ].forEach( ( k ) => f[ k ] && data.append( k, f[ k ].control.value ) );
			data.append( 'website', honey.querySelector( 'input' ).value );
			data.append( 'elapsed', String( Date.now() - started ) );
			data.append( 'source', view === 'form' ? 'form' : 'portal' );
			picker.files().forEach( ( file ) => data.append( 'files[]', file, file.name ) );
			submit.disabled = true;
			submit.textContent = s.sending;
			showAlert( box, '' );
			client
				.post( 'portal/tickets', data )
				.then( ( t ) => showCreated( t ) )
				.catch( ( err ) => {
					showAlert( box, errorText( err ) );
					submit.disabled = false;
					submit.textContent = s.submit;
				} );
		} );
		const card = h( 'section', { class: 'dev3hd-p-card' }, h( 'div', { class: 'dev3hd-p-head' }, h( 'span', { class: 'dev3hd-p-icon' }, icon( 'chat' ) ), h( 'div', {}, h( 'h2', { class: 'dev3hd-p-title' }, s.contactTitle ), h( 'p', { class: 'dev3hd-p-muted' }, config.intro || s.contactIntro ) ) ), form );
		return opts.embed ? card : card;
	};

	const showCreated = ( t ) => {
		const link = new URL( loc.href );
		link.searchParams.set( 'ticket', t.number );
		link.searchParams.set( 'key', t.key );
		render(
			h(
				'section',
				{ class: 'dev3hd-p-card dev3hd-p-done', tabindex: '-1' },
				h( 'span', { class: 'dev3hd-p-icon is-ok' }, icon( 'check' ) ),
				h( 'h2', { class: 'dev3hd-p-title' }, sprintf( s.createdTitle, t.number ) ),
				h( 'p', { class: 'dev3hd-p-muted' }, s.createdText ),
				h(
					'div',
					{ class: 'dev3hd-p-actions is-center' },
					h( 'a', { class: 'dev3hd-p-btn dev3hd-p-btn--primary', href: link.toString(), onClick: ( e ) => {
						e.preventDefault();
						openTicket( t.number, t.key );
					} }, s.viewTicket ),
					h( 'button', { type: 'button', class: 'dev3hd-p-btn dev3hd-p-btn--ghost', onClick: () => start() }, s.another )
				)
			)
		);
		const sec = root.querySelector( '.dev3hd-p-done' );
		if ( sec && sec.focus ) {
			sec.focus();
		}
	};

	/* ---------------------------------------------------------- Lost link */
	const lostLink = () => {
		const box = alertBox();
		const email = field( s.email, h( 'input', { type: 'email', class: 'dev3hd-p-input', autocomplete: 'email' } ) );
		const btn = h( 'button', { type: 'submit', class: 'dev3hd-p-btn dev3hd-p-btn--ghost' }, s.sendLinks );
		const form = h( 'form', { class: 'dev3hd-p-form dev3hd-p-inline', novalidate: true }, email.wrap, btn );
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const v = email.control.value.trim();
			if ( ! EMAIL_RE.test( v ) ) {
				email.setError( s.invalidEmail );
				email.control.focus();
				return;
			}
			email.setError( '' );
			btn.disabled = true;
			client
				.post( 'portal/resend', { email: v } )
				.then( () => showAlert( box, s.linksSent, 'ok' ) )
				.catch( ( err ) => showAlert( box, errorText( err ) ) )
				.finally( () => {
					btn.disabled = false;
				} );
		} );
		return h(
			'section',
			{ class: 'dev3hd-p-card dev3hd-p-subtle' },
			h( 'h3', { class: 'dev3hd-p-subtitle' }, s.lostLink ),
			h( 'p', { class: 'dev3hd-p-muted' }, s.lostLinkText ),
			form,
			box,
			h( 'p', { class: 'dev3hd-p-small' }, s.loginHint, ' ', h( 'a', { href: config.loginUrl }, s.login ) )
		);
	};

	/* --------------------------------------------------------- My tickets */
	const ticketList = ( rows ) => {
		if ( ! rows.length ) {
			return h( 'p', { class: 'dev3hd-p-muted dev3hd-p-empty' }, s.noTickets );
		}
		return h(
			'ul',
			{ class: 'dev3hd-p-list' },
			rows.map( ( r ) =>
				h(
					'li',
					{},
					h(
						'a',
						{ href: `?ticket=${ r.number }&key=${ r.key }`, class: 'dev3hd-p-row', onClick: ( e ) => {
							e.preventDefault();
							openTicket( r.number, r.key );
						} },
						h( 'span', { class: 'dev3hd-p-row-main' }, h( 'strong', {}, r.subject ), h( 'span', { class: 'dev3hd-p-muted' }, `#${ r.number } · ${ s.updated } ${ shortDate( r.updated_at ) }` ) ),
						h( 'span', { class: 'dev3hd-p-row-side' }, r.awaiting ? h( 'span', { class: 'dev3hd-p-flag' }, s.awaiting ) : null, badge( r.status, r.status_label ) )
					)
				)
			)
		);
	};

	const home = ( tab = 'list' ) => {
		render( spinner() );
		return client
			.get( 'portal/tickets' )
			.then( ( rows ) => {
				const active = rows.length ? tab : 'new';
				const tabs = h(
					'div',
					{ class: 'dev3hd-p-tabs', role: 'tablist', 'aria-label': s.myTickets },
					h( 'button', { type: 'button', role: 'tab', 'aria-selected': active === 'list' ? 'true' : 'false', class: active === 'list' ? 'is-active' : '', onClick: () => home( 'list' ) }, `${ s.myTickets } (${ rows.length })` ),
					h( 'button', { type: 'button', role: 'tab', 'aria-selected': active === 'new' ? 'true' : 'false', class: active === 'new' ? 'is-active' : '', onClick: () => home( 'new' ) }, s.newTicket )
				);
				render( h( 'div', { class: 'dev3hd-p-stack' }, tabs, active === 'list' ? h( 'section', { class: 'dev3hd-p-card' }, ticketList( rows ) ) : showForm() ) );
			} )
			.catch( ( err ) => render( h( 'div', { class: 'dev3hd-p-card' }, h( 'p', { role: 'alert' }, errorText( err ) ) ) ) );
	};

	/* ------------------------------------------------------------ Ticket */
	const openTicket = ( number, key, rate = 0 ) => {
		setUrl( key ? { ticket: number, key } : { ticket: number } );
		render( spinner() );
		return client
			.get( `portal/tickets/${ number }`, key ? { key } : {} )
			.then( ( t ) => drawTicket( t, key, rate ) )
			.catch( () =>
				render(
					h(
						'section',
						{ class: 'dev3hd-p-card' },
						h( 'p', { role: 'alert' }, s.notFound ),
						h( 'div', { class: 'dev3hd-p-actions' }, h( 'button', { type: 'button', class: 'dev3hd-p-btn dev3hd-p-btn--ghost', onClick: () => start( true ) }, s.back ) )
					),
					config.loggedIn ? null : lostLink()
				)
			);
	};

	const rating = ( t, key, preset ) => {
		const box = alertBox();
		const labels = config.ratingLabels || { 1: 'Not good', 2: 'Okay', 3: 'Great' };
		const name = id( 'rate' );
		let choice = [ 1, 2, 3 ].includes( preset ) ? preset : 0;
		const opts = [ 3, 2, 1 ].map( ( v ) => {
			const input = h( 'input', { type: 'radio', name, value: String( v ), checked: choice === v } );
			input.addEventListener( 'change', () => {
				choice = v;
			} );
			return h( 'label', { class: `dev3hd-p-rate is-${ v }` }, input, h( 'span', {}, labels[ v ] ) );
		} );
		const comment = field( s.rateComment, h( 'textarea', { class: 'dev3hd-p-textarea', rows: 2, maxlength: 1000 } ) );
		const btn = h( 'button', { type: 'submit', class: 'dev3hd-p-btn dev3hd-p-btn--primary' }, s.rateSubmit );
		const form = h( 'form', { class: 'dev3hd-p-form' }, h( 'fieldset', {}, h( 'legend', {}, s.rateTitle ), h( 'div', { class: 'dev3hd-p-rates' }, opts ) ), comment.wrap, box, h( 'div', { class: 'dev3hd-p-actions' }, btn ) );
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			if ( ! choice ) {
				showAlert( box, s.rateTitle );
				return;
			}
			btn.disabled = true;
			client
				.post( `portal/tickets/${ t.number }/rate`, { key, rating: choice, comment: comment.control.value } )
				.then( ( next ) => drawTicket( next, key, 0, s.rated ) )
				.catch( ( err ) => {
					showAlert( box, errorText( err ) );
					btn.disabled = false;
				} );
		} );
		return h( 'section', { class: 'dev3hd-p-card dev3hd-p-rating' }, form );
	};

	const drawTicket = ( t, key, rate = 0, flash = '' ) => {
		const labels = config.ratingLabels || {};
		const msgs = h(
			'ol',
			{ class: 'dev3hd-p-thread' },
			t.replies.map( ( r ) =>
				h(
					'li',
					{ class: `dev3hd-p-msg ${ r.mine ? 'is-mine' : 'is-team' }` },
					h( 'div', { class: 'dev3hd-p-msg-head' }, h( 'strong', {}, r.mine ? s.you : r.name || s.support ), h( 'time', { datetime: r.created_at || '' }, shortDate( r.created_at ) ) ),
					r.body ? h( 'p', { class: 'dev3hd-p-pre' }, r.body ) : null,
					r.attachments && r.attachments.length
						? h( 'ul', { class: 'dev3hd-p-files is-links' }, r.attachments.map( ( a ) => h( 'li', {}, h( 'a', { href: a.url, target: '_blank', rel: 'noopener' }, icon( a.image ? 'file' : 'clip' ), a.name ) ) ) )
						: null
				)
			)
		);
		const parts = [];
		if ( flash ) {
			parts.push( h( 'div', { class: 'dev3hd-p-alert is-ok', role: 'status' }, flash ) );
		}
		parts.push(
			h(
				'section',
				{ class: 'dev3hd-p-card' },
				h(
					'div',
					{ class: 'dev3hd-p-ticket-head' },
					config.loggedIn ? h( 'button', { type: 'button', class: 'dev3hd-p-link dev3hd-p-back', onClick: () => start( true ) }, icon( 'back' ), s.back ) : null,
					h( 'div', { class: 'dev3hd-p-head' }, h( 'h2', { class: 'dev3hd-p-title' }, t.subject ), badge( t.status, t.status_label ) ),
					h( 'p', { class: 'dev3hd-p-muted' }, `${ s.ticket } #${ t.number }${ t.category ? ` · ${ t.category }` : '' } · ${ shortDate( t.created_at ) }` )
				),
				msgs
			)
		);
		if ( t.can_rate ) {
			parts.push( rating( t, key, rate ) );
		} else if ( t.rating ) {
			parts.push( h( 'p', { class: 'dev3hd-p-muted dev3hd-p-center' }, `${ s.yourRating }: ${ labels[ t.rating ] || t.rating }` ) );
		}
		if ( t.status === 'resolved' ) {
			parts.push( h( 'p', { class: 'dev3hd-p-note' }, s.resolvedNote ) );
		}
		if ( t.can_reply ) {
			const box = alertBox();
			const body = field( s.reply, h( 'textarea', { class: 'dev3hd-p-textarea', rows: 4, maxlength: 20000 } ) );
			const picker = filePicker();
			const send = h( 'button', { type: 'submit', class: 'dev3hd-p-btn dev3hd-p-btn--primary' }, s.sendReply );
			const closeBtn = t.can_close
				? h( 'button', { type: 'button', class: 'dev3hd-p-btn dev3hd-p-btn--ghost', onClick: () => {
					if ( ! confirmFn( s.confirmClose ) ) {
						return;
					}
					client
						.post( `portal/tickets/${ t.number }/close`, { key } )
						.then( ( next ) => drawTicket( next, key ) )
						.catch( ( err ) => showAlert( box, errorText( err ) ) );
				} }, icon( 'check' ), s.closeTicket )
				: null;
			const form = h( 'form', { class: 'dev3hd-p-form', novalidate: true }, body.wrap, picker.wrap, box, h( 'div', { class: 'dev3hd-p-actions' }, closeBtn, send ) );
			form.addEventListener( 'submit', ( e ) => {
				e.preventDefault();
				if ( ! body.control.value.trim() && ! picker.files().length ) {
					body.setError( s.required );
					body.control.focus();
					return;
				}
				body.setError( '' );
				const data = new window.FormData();
				data.append( 'body', body.control.value );
				if ( key ) {
					data.append( 'key', key );
				}
				picker.files().forEach( ( file ) => data.append( 'files[]', file, file.name ) );
				send.disabled = true;
				send.textContent = s.sending;
				client
					.post( `portal/tickets/${ t.number }/reply`, data )
					.then( ( next ) => drawTicket( next, key ) )
					.catch( ( err ) => {
						showAlert( box, errorText( err ) );
						send.disabled = false;
						send.textContent = s.sendReply;
					} );
			} );
			parts.push( h( 'section', { class: 'dev3hd-p-card' }, form ) );
		} else {
			parts.push( h( 'p', { class: 'dev3hd-p-note' }, s.closedNote ) );
		}
		render( h( 'div', { class: 'dev3hd-p-stack' }, parts ) );
	};

	/* ------------------------------------------------------------- Start */
	const start = ( resetUrl = false ) => {
		if ( resetUrl ) {
			setUrl( {} );
		}
		if ( view !== 'form' && config.loggedIn ) {
			return home();
		}
		if ( ! config.loggedIn && ! config.guest ) {
			render( h( 'section', { class: 'dev3hd-p-card dev3hd-p-center' }, h( 'p', {}, s.loginRequired ), h( 'div', { class: 'dev3hd-p-actions is-center' }, h( 'a', { class: 'dev3hd-p-btn dev3hd-p-btn--primary', href: config.loginUrl }, s.login ) ) ) );
			return Promise.resolve();
		}
		render( h( 'div', { class: 'dev3hd-p-stack' }, showForm(), view === 'form' || config.loggedIn ? null : lostLink() ) );
		return Promise.resolve();
	};

	const number = parseInt( url.searchParams.get( 'ticket' ) || '', 10 );
	const ready = number > 0 && view !== 'form' ? openTicket( number, url.searchParams.get( 'key' ) || '', parseInt( url.searchParams.get( 'rate' ) || '0', 10 ) ) : start();
	return { ready };
}
