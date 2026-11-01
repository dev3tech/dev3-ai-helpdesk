/**
 * Tiny DOM helpers for the support portal and help centre (no framework, small bundle). Text is always set with
 * textContent, never innerHTML, so customer and agent text cannot inject markup.
 */

/**
 * Creates an element.
 *
 * @param {string} tag      Tag.
 * @param {Object} attrs    Attributes: class, text, on* handlers, booleans, dataset via data-*.
 * @param {...*}   children Nodes, strings, arrays; null/false skipped.
 * @return {HTMLElement} Element.
 */
export function h( tag, attrs = {}, ...children ) {
	const el = document.createElement( tag );
	Object.keys( attrs || {} ).forEach( ( key ) => {
		const value = attrs[ key ];
		if ( value === null || value === undefined || value === false ) {
			return;
		}
		if ( key === 'class' ) {
			el.className = value;
		} else if ( key === 'text' ) {
			el.textContent = value;
		} else if ( key === 'value' ) {
			el.value = value;
		} else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
			el.addEventListener( key.slice( 2 ).toLowerCase(), value );
		} else if ( value === true ) {
			el.setAttribute( key, '' );
		} else {
			el.setAttribute( key, String( value ) );
		}
	} );
	append( el, children );
	return el;
}

/**
 * Appends children.
 *
 * @param {HTMLElement} el       Parent.
 * @param {Array}       children Children.
 * @return {HTMLElement} Parent.
 */
export function append( el, children ) {
	children.flat( 3 ).forEach( ( child ) => {
		if ( child === null || child === undefined || child === false || child === '' ) {
			return;
		}
		el.appendChild( typeof child === 'string' || typeof child === 'number' ? document.createTextNode( String( child ) ) : child );
	} );
	return el;
}

let uid = 0;
/**
 * Unique id.
 *
 * @param {string} base Base.
 * @return {string} Id.
 */
export function id( base ) {
	uid += 1;
	return `dev3hd-${ base }-${ uid }`;
}

/**
 * Simple "%1$d" / "%s" formatter.
 *
 * @param {string} text Text.
 * @param {...*}   args Values.
 * @return {string} Text.
 */
export function sprintf( text, ...args ) {
	let i = 0;
	return String( text ).replace( /%(\d+\$)?[sd]/g, ( match, pos ) => {
		const index = pos ? parseInt( pos, 10 ) - 1 : i++;
		return args[ index ] === undefined ? '' : String( args[ index ] );
	} );
}

/**
 * Inline SVG icon (static markup only).
 *
 * @param {string} name Icon name.
 * @return {SVGElement} Icon.
 */
export function icon( name ) {
	const paths = {
		back: 'M15 18l-6-6 6-6',
		check: 'M5 13l4 4L19 7',
		clip: 'M21.4 11.1l-9.2 9.2a6 6 0 01-8.5-8.5l9.2-9.2a4 4 0 015.7 5.7l-9.2 9.2a2 2 0 01-2.8-2.8l8.5-8.5',
		file: 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6',
		chat: 'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',
		mail: 'M4 4h16a2 2 0 012 2v12a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2zM22 6l-10 7L2 6',
		book: 'M4 19.5A2.5 2.5 0 016.5 17H20V3H6.5A2.5 2.5 0 004 5.5v14zM20 17v5H6.5A2.5 2.5 0 014 19.5',
		x: 'M6 6l12 12M18 6L6 18',
		arrow: 'M9 6l6 6-6 6',
	};
	const ns = 'http://www.w3.org/2000/svg';
	const svg = document.createElementNS( ns, 'svg' );
	svg.setAttribute( 'viewBox', '0 0 24 24' );
	svg.setAttribute( 'width', '18' );
	svg.setAttribute( 'height', '18' );
	svg.setAttribute( 'aria-hidden', 'true' );
	svg.setAttribute( 'focusable', 'false' );
	const path = document.createElementNS( ns, 'path' );
	path.setAttribute( 'd', paths[ name ] || paths.arrow );
	path.setAttribute( 'fill', 'none' );
	path.setAttribute( 'stroke', 'currentColor' );
	path.setAttribute( 'stroke-width', '2' );
	path.setAttribute( 'stroke-linecap', 'round' );
	path.setAttribute( 'stroke-linejoin', 'round' );
	svg.appendChild( path );
	return svg;
}
