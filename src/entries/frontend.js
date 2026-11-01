import { mount } from '../frontend/portal';
import { mountHelp, mountVote } from '../frontend/help';
import '../frontend/frontend.css';

const boot = () => {
	const config = window.dev3hdPortal || {};
	document.querySelectorAll( '[data-dev3hd-app="portal"]' ).forEach( ( root ) => mount( root, config ) );
	document.querySelectorAll( '[data-dev3hd-app="help"]' ).forEach( ( root ) => mountHelp( root, config ) );
	document.querySelectorAll( '[data-dev3hd-vote]' ).forEach( ( root ) => mountVote( root, config ) );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
