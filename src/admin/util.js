import { createContext, useContext } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import config from '../core3/config';

export const BootContext = createContext( null );
export const useBoot = () => useContext( BootContext ) || {};

export const STATUS_TONES = {
	new: 'brand',
	open: 'info',
	pending: 'warning',
	on_hold: 'neutral',
	resolved: 'success',
	closed: 'neutral',
};

export const PRIORITY_TONES = {
	low: 'neutral',
	normal: 'info',
	high: 'warning',
	urgent: 'danger',
};

export const SENTIMENT = {
	positive: { tone: 'success', label: __( 'Positive', 'dev3-ai-helpdesk' ) },
	neutral: { tone: 'neutral', label: __( 'Neutral', 'dev3-ai-helpdesk' ) },
	negative: { tone: 'warning', label: __( 'Negative', 'dev3-ai-helpdesk' ) },
	angry: { tone: 'danger', label: __( 'Angry', 'dev3-ai-helpdesk' ) },
};

export const statusLabel = ( s ) => ( config.statuses || {} )[ s ] || s;
export const priorityLabel = ( p ) => ( config.priorities || {} )[ p ] || p;
export const channelLabel = ( c ) => ( config.channels || {} )[ c ] || c;

export const statusOptions = () => Object.keys( config.statuses || {} ).map( ( value ) => ( { value, label: statusLabel( value ) } ) );
export const priorityOptions = () => Object.keys( config.priorities || {} ).map( ( value ) => ( { value, label: priorityLabel( value ) } ) );

/**
 * Relative time ("5 min ago").
 *
 * @param {string} iso ISO date.
 * @param {number} now Now (ms, tests).
 * @return {string} Text.
 */
export function timeAgo( iso, now = Date.now() ) {
	if ( ! iso ) {
		return '';
	}
	const diff = Math.round( ( now - new Date( iso ).getTime() ) / 1000 );
	if ( isNaN( diff ) ) {
		return '';
	}
	if ( diff < 60 ) {
		return __( 'just now', 'dev3-ai-helpdesk' );
	}
	if ( diff < 3600 ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min ago', 'dev3-ai-helpdesk' ), Math.floor( diff / 60 ) );
	}
	if ( diff < 86400 ) {
		/* translators: %d: hours. */
		return sprintf( __( '%d h ago', 'dev3-ai-helpdesk' ), Math.floor( diff / 3600 ) );
	}
	if ( diff < 86400 * 30 ) {
		/* translators: %d: days. */
		return sprintf( __( '%d d ago', 'dev3-ai-helpdesk' ), Math.floor( diff / 86400 ) );
	}
	return new Date( iso ).toLocaleDateString();
}

/**
 * Date + time in the browser locale.
 *
 * @param {string} iso ISO date.
 * @return {string} Text.
 */
export const dateTime = ( iso ) => ( iso ? new Date( iso ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } ) : '' );

/**
 * Seconds → "3 h 20 min" / "2 d 4 h".
 *
 * @param {number|null} seconds Seconds.
 * @return {string} Text.
 */
export function duration( seconds ) {
	if ( seconds === null || seconds === undefined ) {
		return '—';
	}
	const s = Math.abs( Math.round( seconds ) );
	if ( s < 3600 ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min', 'dev3-ai-helpdesk' ), Math.max( 1, Math.round( s / 60 ) ) );
	}
	if ( s < 86400 ) {
		const h = Math.floor( s / 3600 );
		const m = Math.round( ( s % 3600 ) / 60 );
		/* translators: 1: hours, 2: minutes. */
		return m ? sprintf( __( '%1$d h %2$d min', 'dev3-ai-helpdesk' ), h, m ) : sprintf( __( '%d h', 'dev3-ai-helpdesk' ), h );
	}
	const d = Math.floor( s / 86400 );
	const h = Math.round( ( s % 86400 ) / 3600 );
	/* translators: 1: days, 2: hours. */
	return h ? sprintf( __( '%1$d d %2$d h', 'dev3-ai-helpdesk' ), d, h ) : sprintf( __( '%d d', 'dev3-ai-helpdesk' ), d );
}

/**
 * SLA badge for a ticket row: { tone, label } or null.
 *
 * @param {Object} sla Sla state.
 * @return {Object|null} Badge.
 */
export function slaBadge( sla ) {
	if ( ! sla ) {
		return null;
	}
	if ( sla.paused ) {
		return { tone: 'neutral', label: __( 'SLA paused', 'dev3-ai-helpdesk' ) };
	}
	const part = sla.first && sla.first.met === null ? sla.first : sla.resolve || sla.first;
	if ( ! part || part.met !== null ) {
		if ( part && part.met === false ) {
			return { tone: 'danger', label: __( 'SLA missed', 'dev3-ai-helpdesk' ) };
		}
		return null;
	}
	if ( part.overdue ) {
		/* translators: %s: duration. */
		return { tone: 'danger', label: sprintf( __( 'Overdue %s', 'dev3-ai-helpdesk' ), duration( part.left ) ) };
	}
	const tone = part.left < 3600 * 2 ? 'warning' : 'neutral';
	/* translators: %s: duration. */
	return { tone, label: sprintf( __( 'Due in %s', 'dev3-ai-helpdesk' ), duration( part.left ) ) };
}

/**
 * File size.
 *
 * @param {number} bytes Bytes.
 * @return {string} Text.
 */
export const fileSize = ( bytes ) => ( bytes > 1048576 ? `${ ( bytes / 1048576 ).toFixed( 1 ) } MB` : `${ Math.max( 1, Math.round( bytes / 1024 ) ) } KB` );

/**
 * Saves a text file in the browser.
 *
 * @param {string} filename Name.
 * @param {string} text     Content.
 * @param {string} type     Mime.
 */
export function download( filename, text, type = 'text/csv;charset=utf-8' ) {
	const blob = new window.Blob( [ '﻿' + text ], { type } );
	const url = window.URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	a.remove();
	window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
}

/**
 * Query string from an object (empty values dropped).
 *
 * @param {Object} params Params.
 * @return {string} "a=1&b=2".
 */
export const qs = ( params ) =>
	Object.keys( params )
		.filter( ( k ) => params[ k ] !== '' && params[ k ] !== null && params[ k ] !== undefined )
		.map( ( k ) => `${ encodeURIComponent( k ) }=${ encodeURIComponent( params[ k ] ) }` )
		.join( '&' );

/**
 * Initials for avatars.
 *
 * @param {string} name Name.
 * @return {string} "AL".
 */
export const initials = ( name ) =>
	String( name || '?' )
		.split( /\s+/ )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( w ) => w[ 0 ].toUpperCase() )
		.join( '' );
