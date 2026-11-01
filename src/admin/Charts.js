import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatNumber } from '../core3/ui';

/**
 * Created vs resolved per day: paired thin bars (categorical slots 1 and 2), one axis, legend,
 * hover/focus tooltip and a screen-reader table.
 *
 * @param {Object} props      Props.
 * @param {Array}  props.data [ { date, label, created, resolved } ].
 * @return {Element} Chart.
 */
export function TrendChart( { data = [] } ) {
	const [ hover, setHover ] = useState( null );
	const max = Math.max( 1, ...data.map( ( d ) => Math.max( d.created, d.resolved ) ) );
	const nice = max <= 4 ? 4 : Math.ceil( max / 4 ) * 4;
	const active = hover !== null ? data[ hover ] : null;
	const created = __( 'Created', 'dev3-ai-helpdesk' );
	const resolved = __( 'Resolved', 'dev3-ai-helpdesk' );
	return (
		<div className="dev3hd-chart">
			<div className="dev3hd-chart__legend" aria-hidden="true">
				<span><i className="dev3hd-chart__key is-a" />{ created }</span>
				<span><i className="dev3hd-chart__key is-b" />{ resolved }</span>
			</div>
			<div className="dev3hd-chart__plot" onMouseLeave={ () => setHover( null ) }>
				<div className="dev3hd-chart__grid" aria-hidden="true">
					<span>{ formatNumber( nice ) }</span>
					<span>{ formatNumber( nice / 2 ) }</span>
					<span>0</span>
				</div>
				<div className="dev3hd-chart__cols">
					{ data.map( ( d, i ) => (
						<button
							type="button"
							key={ d.date }
							className={ 'dev3hd-chart__col' + ( hover === i ? ' is-active' : '' ) }
							onMouseEnter={ () => setHover( i ) }
							onFocus={ () => setHover( i ) }
							onBlur={ () => setHover( null ) }
							aria-label={ `${ d.label }: ${ d.created } ${ created }, ${ d.resolved } ${ resolved }` }
						>
							<span className="dev3hd-chart__bar is-a" style={ { height: d.created ? `max(3px, ${ ( d.created / nice ) * 100 }%)` : 0 } } />
							<span className="dev3hd-chart__bar is-b" style={ { height: d.resolved ? `max(3px, ${ ( d.resolved / nice ) * 100 }%)` : 0 } } />
						</button>
					) ) }
				</div>
				{ active && (
					<div className="dev3hd-chart__tip" role="status" style={ { left: `${ Math.min( 85, Math.max( 15, ( ( hover + 0.5 ) / data.length ) * 100 ) ) }%` } }>
						<span>{ active.label }</span>
						<strong>{ formatNumber( active.created ) } { created } · { formatNumber( active.resolved ) } { resolved }</strong>
					</div>
				) }
			</div>
			<div className="dev3hd-chart__axis" aria-hidden="true">
				<span>{ data.length ? data[ 0 ].label : '' }</span>
				<span>{ data.length ? data[ data.length - 1 ].label : '' }</span>
			</div>
			<table className="screen-reader-text">
				<caption>{ __( 'Tickets created and resolved per day', 'dev3-ai-helpdesk' ) }</caption>
				<thead>
					<tr>
						<th scope="col">{ __( 'Date', 'dev3-ai-helpdesk' ) }</th>
						<th scope="col">{ created }</th>
						<th scope="col">{ resolved }</th>
					</tr>
				</thead>
				<tbody>
					{ data.map( ( d ) => (
						<tr key={ d.date }>
							<td>{ d.label }</td>
							<td>{ d.created }</td>
							<td>{ d.resolved }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * Ranked horizontal bars (one hue; values in ink).
 *
 * @param {Object}   props        Props.
 * @param {Array}    props.rows   [ { key, label, value, hint } ].
 * @param {string}   props.label  Accessible name.
 * @param {Function} props.format Formatter.
 * @return {Element} List.
 */
export function BarList( { rows = [], label, format = formatNumber } ) {
	const max = Math.max( 1, ...rows.map( ( r ) => r.value ) );
	return (
		<ul className="dev3hd-barlist" aria-label={ label }>
			{ rows.map( ( r ) => (
				<li key={ r.key } className="dev3hd-barlist__row">
					<span className="dev3hd-barlist__top">
						<span className="dev3hd-barlist__label">{ r.label }</span>
						<span className="dev3hd-barlist__value">{ format( r.value ) }</span>
					</span>
					<span className="dev3hd-barlist__track" aria-hidden="true">
						<span className="dev3hd-barlist__fill" style={ { width: `${ Math.max( 2, ( r.value / max ) * 100 ) }%` } } />
					</span>
					{ r.hint && <span className="dev3hd-barlist__hint">{ r.hint }</span> }
				</li>
			) ) }
		</ul>
	);
}
