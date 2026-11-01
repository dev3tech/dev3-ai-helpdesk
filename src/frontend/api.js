/**
 * Front-end REST client (fetch; works with pretty and plain permalinks).
 */

/**
 * Builds a REST URL.
 *
 * @param {string} base   Base (".../wp-json/dev3hd/v1/" or "...?rest_route=/dev3hd/v1/").
 * @param {string} path   Path.
 * @param {Object} params Query params.
 * @return {string} URL.
 */
export function restUrl( base, path, params = {} ) {
	let url = base + path.replace( /^\//, '' );
	const query = Object.keys( params )
		.filter( ( k ) => params[ k ] !== undefined && params[ k ] !== null && params[ k ] !== '' )
		.map( ( k ) => `${ encodeURIComponent( k ) }=${ encodeURIComponent( params[ k ] ) }` )
		.join( '&' );
	if ( query ) {
		url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
	}
	return url;
}

/**
 * Creates a client bound to the boot config.
 *
 * @param {Object}   config    window.dev3hdPortal.
 * @param {Function} fetchImpl fetch (tests).
 * @return {Object} { get, post }
 */
export function createClient( config, fetchImpl ) {
	const doFetch = fetchImpl || ( ( ...args ) => window.fetch( ...args ) );
	const headers = () => ( config.nonce ? { 'X-WP-Nonce': config.nonce } : {} );
	const handle = ( res ) =>
		res
			.json()
			.catch( () => ( {} ) )
			.then( ( body ) => {
				if ( ! res.ok ) {
					const err = new Error( ( body && body.message ) || 'error' );
					err.code = body && body.code;
					err.status = res.status;
					throw err;
				}
				return body;
			} );
	return {
		get: ( path, params ) =>
			doFetch( restUrl( config.restUrl, path, params ), { credentials: 'same-origin', headers: headers() } ).then( handle ),
		post: ( path, data ) => {
			const isForm = typeof FormData !== 'undefined' && data instanceof FormData;
			return doFetch( restUrl( config.restUrl, path ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: isForm ? headers() : { ...headers(), 'Content-Type': 'application/json' },
				body: isForm ? data : JSON.stringify( data || {} ),
			} ).then( handle );
		},
	};
}
