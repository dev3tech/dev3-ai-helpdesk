/**
 * Help centre enhancements: instant search results (semantic when available, keyword otherwise) over the
 * server-rendered page, and "Was this helpful?" votes on article pages.
 */
import { createClient } from './api';
import { h } from './dom';

/**
 * Renders results into the live region.
 *
 * @param {HTMLElement} box     Results container.
 * @param {Array}       results Results.
 * @param {Object}      s       Strings.
 */
export function drawResults( box, results, s ) {
	box.textContent = '';
	if ( ! results.length ) {
		box.appendChild( h( 'p', { class: 'dev3hd-hc-empty' }, s.noResults ) );
		return;
	}
	box.appendChild(
		h(
			'ul',
			{ class: 'dev3hd-hc-hits', 'aria-label': s.searchResults },
			results.map( ( r ) => h( 'li', {}, h( 'a', { href: r.url }, h( 'span', { class: 'dev3hd-hc-hit-title' }, r.title ), r.category ? h( 'span', { class: 'dev3hd-hc-hit-cat' }, r.category ) : null, h( 'span', { class: 'dev3hd-hc-muted' }, r.excerpt ) ) ) )
		)
	);
}

/**
 * Mounts the help centre search.
 *
 * @param {HTMLElement} root   .dev3hd-hc element.
 * @param {Object}      config Boot config.
 * @param {Object}      deps   { client } (tests).
 * @return {Object} { search( q ): Promise }
 */
export function mountHelp( root, config, deps = {} ) {
	const s = config.strings || {};
	const client = deps.client || createClient( config );
	const form = root.querySelector( '.dev3hd-hc-search' );
	const input = root.querySelector( '.dev3hd-hc-input' );
	const box = root.querySelector( '.dev3hd-hc-results' );
	if ( ! form || ! input || ! box ) {
		return { search: () => Promise.resolve() };
	}
	let timer = null;
	let seq = 0;
	const search = ( q ) => {
		const query = String( q || '' ).trim();
		const mine = ++seq;
		if ( query.length < 3 ) {
			box.textContent = '';
			return Promise.resolve();
		}
		box.setAttribute( 'aria-busy', 'true' );
		return client
			.get( 'kb/search', { q: query, limit: 8 } )
			.then( ( res ) => {
				if ( mine === seq ) {
					drawResults( box, ( res && res.results ) || [], s );
				}
			} )
			.catch( () => {
				if ( mine === seq ) {
					form.submit();
				}
			} )
			.finally( () => box.removeAttribute( 'aria-busy' ) );
	};
	input.addEventListener( 'input', () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( () => search( input.value ), 250 );
	} );
	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		window.clearTimeout( timer );
		search( input.value );
	} );
	return { search };
}

/**
 * Mounts article votes.
 *
 * @param {HTMLElement} root   [data-dev3hd-vote] element.
 * @param {Object}      config Boot config.
 * @param {Object}      deps   { client }.
 */
export function mountVote( root, config, deps = {} ) {
	const s = config.strings || {};
	const client = deps.client || createClient( config );
	const msg = root.querySelector( '.dev3hd-vote-msg' );
	root.querySelectorAll( '.dev3hd-vote-btn' ).forEach( ( btn ) => {
		btn.addEventListener( 'click', () => {
			root.querySelectorAll( '.dev3hd-vote-btn' ).forEach( ( b ) => {
				b.disabled = true;
			} );
			btn.setAttribute( 'aria-pressed', 'true' );
			client
				.post( 'kb/vote', { id: parseInt( root.getAttribute( 'data-dev3hd-vote' ), 10 ), helpful: btn.getAttribute( 'data-vote' ) === 'yes' } )
				.then( () => {
					if ( msg ) {
						msg.textContent = s.helpful;
					}
				} )
				.catch( () => {
					if ( msg ) {
						msg.textContent = s.error;
					}
				} );
		} );
	} );
}
