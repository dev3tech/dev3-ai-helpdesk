import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Icon, Notice, PageHeader, SkeletonCard, Stat, StatGrid, Stack, formatNumber } from '../core3/ui';
import { BarList, TrendChart } from './Charts';
import { PRIORITY_TONES, duration, priorityLabel, slaBadge, timeAgo, useBoot } from './util';

const SETUP = {
	pages: { label: __( 'Support portal and help-centre pages are published', 'dev3-ai-helpdesk' ), href: '#/settings/general' },
	mail: { label: __( 'Email piping (IMAP or webhook) is connected', 'dev3-ai-helpdesk' ), href: '#/settings/mail' },
	kb: { label: __( 'At least one help article is published', 'dev3-ai-helpdesk' ), href: '#/kb' },
	agents: { label: __( 'Your support agents are added', 'dev3-ai-helpdesk' ), href: '#/settings/agents' },
	ai: { label: __( 'DEV3 AI is connected (Gemini API key)', 'dev3-ai-helpdesk' ), href: '#/api' },
};

export default function Dashboard( { navigate } ) {
	const boot = useBoot();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'stats' ).then( setData ).catch( setError );
	};
	useEffect( load, [] );

	const header = (
		<PageHeader
			title={ __( 'Dashboard', 'dev3-ai-helpdesk' ) }
			description={ sprintf( /* translators: %s: agent name. */ __( 'Welcome back, %s. Here is what needs your attention.', 'dev3-ai-helpdesk' ), ( boot.me && boot.me.name ) || '' ) }
			actions={
				<Button variant="primary" icon="inbox" onClick={ () => navigate( 'tickets' ) }>
					{ __( 'Open inbox', 'dev3-ai-helpdesk' ) }
				</Button>
			}
		/>
	);
	if ( error ) {
		return (
			<>
				{ header }
				<ErrorNotice error={ error } onRetry={ load } />
			</>
		);
	}
	if ( ! data ) {
		return (
			<>
				{ header }
				<SkeletonCard lines={ 4 } />
			</>
		);
	}
	const c = data.counts;
	const p = data.period;
	const setupLeft = data.setup ? data.setup.filter( ( s ) => ! s.done ) : [];

	return (
		<Stack gap={ 16 }>
			{ header }
			{ boot.ai && ! boot.ai.key_set && boot.me && boot.me.manager && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-helpdesk' ) } actions={ <Button size="sm" href="#/api">{ __( 'Add API key', 'dev3-ai-helpdesk' ) }</Button> }>
					{ __( 'Add a Google Gemini API key to triage new tickets, draft replies from your knowledge base and summarise long threads. Everything else works without it.', 'dev3-ai-helpdesk' ) }
				</Notice>
			) }
			<StatGrid min={ 150 }>
				<Stat label={ __( 'Open tickets', 'dev3-ai-helpdesk' ) } value={ formatNumber( c.active ) } icon="inbox" tone="brand" hint={ sprintf( /* translators: %d: count. */ __( '%d new', 'dev3-ai-helpdesk' ), c.new ) } />
				<Stat label={ __( 'Unassigned', 'dev3-ai-helpdesk' ) } value={ formatNumber( c.unassigned ) } icon="users" tone={ c.unassigned ? 'warning' : 'neutral' } />
				<Stat label={ __( 'Overdue (SLA)', 'dev3-ai-helpdesk' ) } value={ formatNumber( c.overdue ) } icon="clock" tone={ c.overdue ? 'danger' : 'success' } />
				<Stat label={ __( 'Avg. first response', 'dev3-ai-helpdesk' ) } value={ duration( p.first_response ) } icon="bolt" hint={ __( 'Last 30 days', 'dev3-ai-helpdesk' ) } />
				<Stat label={ __( 'Customer satisfaction', 'dev3-ai-helpdesk' ) } value={ p.csat === null ? '—' : `${ p.csat }%` } icon="success" tone={ p.csat !== null && p.csat < 70 ? 'warning' : 'success' } hint={ sprintf( /* translators: %d: ratings. */ __( '%d ratings', 'dev3-ai-helpdesk' ), p.ratings ) } />
				<Stat label={ __( 'Resolved this week', 'dev3-ai-helpdesk' ) } value={ formatNumber( data.resolved7 ) } icon="check" />
			</StatGrid>
			<Columns min={ 320 }>
				<Card title={ __( 'Created vs resolved', 'dev3-ai-helpdesk' ) } description={ __( 'Last 14 days', 'dev3-ai-helpdesk' ) }>
					<TrendChart data={ data.trend } />
				</Card>
				<Card title={ __( 'Open tickets by category', 'dev3-ai-helpdesk' ) }>
					{ data.by_cat.length ? (
						<BarList label={ __( 'Open tickets by category', 'dev3-ai-helpdesk' ) } rows={ data.by_cat.map( ( r ) => ( { key: r.id || 'none', label: r.label, value: r.count } ) ) } />
					) : (
						<EmptyState compact icon="check" title={ __( 'No open tickets', 'dev3-ai-helpdesk' ) } description={ __( 'Inbox zero. Nice work.', 'dev3-ai-helpdesk' ) } />
					) }
				</Card>
			</Columns>
			<Columns min={ 320 }>
				<Card title={ __( 'Needs attention', 'dev3-ai-helpdesk' ) } description={ __( 'Urgent, high priority and overdue tickets', 'dev3-ai-helpdesk' ) } flush>
					{ data.attention.length ? (
						<ul className="dev3hd-attention">
							{ data.attention.map( ( t ) => {
								const sla = slaBadge( t.sla );
								return (
									<li key={ t.id }>
										<a href={ `#/tickets/${ t.id }` } className="dev3hd-attention__link">
											<span className="dev3hd-attention__main">
												<span className="dev3hd-attention__subject">#{ t.number } { t.subject }</span>
												<span className="dev3hd-muted">
													{ t.name } · { timeAgo( t.updated_at ) }
												</span>
											</span>
											<span className="dev3hd-attention__badges">
												<Badge tone={ PRIORITY_TONES[ t.priority ] }>{ priorityLabel( t.priority ) }</Badge>
												{ sla && <Badge tone={ sla.tone }>{ sla.label }</Badge> }
											</span>
										</a>
									</li>
								);
							} ) }
						</ul>
					) : (
						<EmptyState compact icon="check" title={ __( 'Nothing urgent', 'dev3-ai-helpdesk' ) } description={ __( 'No urgent or overdue tickets right now.', 'dev3-ai-helpdesk' ) } />
					) }
				</Card>
				{ data.setup && (
					<Card title={ __( 'Setup checklist', 'dev3-ai-helpdesk' ) } description={ setupLeft.length ? sprintf( /* translators: %d: steps left. */ __( '%d steps left', 'dev3-ai-helpdesk' ), setupLeft.length ) : __( 'All set', 'dev3-ai-helpdesk' ) }>
						<ul className="dev3hd-checklist">
							{ data.setup.map( ( s ) => (
								<li key={ s.id } className={ s.done ? 'is-done' : '' }>
									<span className="dev3hd-checklist__icon" aria-hidden="true">
										<Icon name={ s.done ? 'check' : 'clock' } size={ 14 } />
									</span>
									<span className="dev3hd-checklist__label">
										{ SETUP[ s.id ].label }
										<span className="screen-reader-text">{ s.done ? __( '(done)', 'dev3-ai-helpdesk' ) : __( '(to do)', 'dev3-ai-helpdesk' ) }</span>
									</span>
									{ ! s.done && (
										<Button size="sm" variant="ghost" href={ SETUP[ s.id ].href }>
											{ __( 'Set up', 'dev3-ai-helpdesk' ) }
										</Button>
									) }
								</li>
							) ) }
						</ul>
					</Card>
				) }
			</Columns>
		</Stack>
	);
}
