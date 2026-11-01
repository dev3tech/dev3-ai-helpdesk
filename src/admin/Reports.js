import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Columns, ErrorNotice, Inline, PageHeader, ResponsiveTable, Select, SkeletonCard, Stack, Stat, StatGrid, formatNumber, useToast } from '../core3/ui';
import { BarList, TrendChart } from './Charts';
import { download, duration } from './util';

const PERIODS = [
	{ value: '7', label: __( 'Last 7 days', 'dev3-ai-helpdesk' ) },
	{ value: '30', label: __( 'Last 30 days', 'dev3-ai-helpdesk' ) },
	{ value: '90', label: __( 'Last 90 days', 'dev3-ai-helpdesk' ) },
	{ value: '365', label: __( 'Last 12 months', 'dev3-ai-helpdesk' ) },
];

export default function Reports() {
	const toast = useToast();
	const [ days, setDays ] = useState( '30' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		setData( null );
		request( `reports?days=${ days }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ days ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const exportCsv = () =>
		request( `reports/export?days=${ days }` )
			.then( ( res ) => download( res.filename, res.csv ) )
			.catch( ( e ) => toast.error( e ) );

	const t = data && data.totals;
	return (
		<Stack gap={ 16 }>
			<PageHeader
				title={ __( 'Reports', 'dev3-ai-helpdesk' ) }
				description={ __( 'Volume, response times, SLA compliance and customer satisfaction.', 'dev3-ai-helpdesk' ) }
				actions={
					<Inline>
						<Select label={ __( 'Period', 'dev3-ai-helpdesk' ) } hideLabel value={ days } onChange={ setDays } options={ PERIODS } />
						<Button icon="external" variant="ghost" onClick={ exportCsv }>
							{ __( 'Export CSV', 'dev3-ai-helpdesk' ) }
						</Button>
					</Inline>
				}
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data && (
				<>
					<StatGrid min={ 170 }>
						<Stat label={ __( 'Tickets created', 'dev3-ai-helpdesk' ) } value={ formatNumber( t.created ) } icon="inbox" tone="brand" />
						<Stat label={ __( 'Resolved', 'dev3-ai-helpdesk' ) } value={ formatNumber( t.resolved ) } icon="check" tone="success" />
						<Stat label={ __( 'Avg. first response', 'dev3-ai-helpdesk' ) } value={ duration( t.first_response ) } icon="bolt" />
						<Stat label={ __( 'Avg. resolution time', 'dev3-ai-helpdesk' ) } value={ duration( t.resolution_time ) } icon="clock" />
						<Stat label={ __( 'SLA compliance', 'dev3-ai-helpdesk' ) } value={ t.sla_compliance === null ? '—' : `${ t.sla_compliance }%` } icon="alert" tone={ t.sla_compliance !== null && t.sla_compliance < 80 ? 'warning' : 'neutral' } hint={ __( 'First responses on time', 'dev3-ai-helpdesk' ) } />
						<Stat label={ __( 'CSAT', 'dev3-ai-helpdesk' ) } value={ t.csat === null ? '—' : `${ t.csat }%` } icon="success" hint={ sprintf( /* translators: %d: ratings. */ __( '%d ratings', 'dev3-ai-helpdesk' ), t.ratings ) } />
					</StatGrid>
					<Card title={ __( 'Created vs resolved', 'dev3-ai-helpdesk' ) }>
						<TrendChart data={ data.trend } />
					</Card>
					<Card title={ __( 'By agent', 'dev3-ai-helpdesk' ) } flush>
						<ResponsiveTable
							caption={ __( 'Tickets by agent', 'dev3-ai-helpdesk' ) }
							rows={ data.by_agent }
							defaultSort={ { key: 'assigned', dir: 'desc' } }
							columns={ [
								{ key: 'name', label: __( 'Agent', 'dev3-ai-helpdesk' ), primary: true, sortable: true },
								{ key: 'assigned', label: __( 'Tickets', 'dev3-ai-helpdesk' ), sortable: true, align: 'end' },
								{ key: 'resolved', label: __( 'Resolved', 'dev3-ai-helpdesk' ), sortable: true, align: 'end' },
								{ key: 'first_response', label: __( 'Avg. first response', 'dev3-ai-helpdesk' ), sortable: true, align: 'end', render: ( r ) => duration( r.first_response ) },
								{ key: 'csat', label: __( 'CSAT', 'dev3-ai-helpdesk' ), sortable: true, align: 'end', render: ( r ) => ( r.csat === null ? '—' : `${ r.csat }%` ) },
							] }
							empty={ { icon: 'users', title: __( 'No tickets in this period', 'dev3-ai-helpdesk' ) } }
						/>
					</Card>
					<Columns min={ 280 }>
						<Card title={ __( 'By category', 'dev3-ai-helpdesk' ) }>
							<BarList label={ __( 'By category', 'dev3-ai-helpdesk' ) } rows={ data.by_category.map( ( r ) => ( { key: r.label, label: r.label, value: r.count, hint: sprintf( /* translators: %d: resolved. */ __( '%d resolved', 'dev3-ai-helpdesk' ), r.resolved ) } ) ) } />
						</Card>
						<Card title={ __( 'By channel', 'dev3-ai-helpdesk' ) }>
							<BarList label={ __( 'By channel', 'dev3-ai-helpdesk' ) } rows={ data.by_channel.map( ( r ) => ( { key: r.label, label: r.label, value: r.count } ) ) } />
						</Card>
						<Card title={ __( 'By priority', 'dev3-ai-helpdesk' ) }>
							<BarList label={ __( 'By priority', 'dev3-ai-helpdesk' ) } rows={ data.by_priority.map( ( r ) => ( { key: r.label, label: r.label, value: r.count } ) ) } />
						</Card>
					</Columns>
				</>
			) }
		</Stack>
	);
}
