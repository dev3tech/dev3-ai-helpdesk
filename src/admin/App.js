import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import { BootContext } from './util';
import Dashboard from './Dashboard';
import Tickets from './Tickets';
import TicketDetail from './TicketDetail';
import Knowledge from './Knowledge';
import Canned from './Canned';
import Reports from './Reports';
import SettingsTab from './SettingsTab';
import './admin.css';

const ALL = [ 'dashboard', 'tickets', 'kb', 'canned', 'reports', 'settings', 'api', 'usage' ];

export default function App() {
	const [ route, navigate ] = useHashRoute( ALL, 'dashboard' );
	const [ boot, setBoot ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const manager = !! config.isManager;

	const loadBoot = () =>
		request( 'boot' )
			.then( setBoot )
			.catch( setError );
	const load = () => {
		setError( null );
		loadBoot();
		if ( manager ) {
			request( 'settings' ).then( setSettings ).catch( setError );
		}
	};
	useEffect( load, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			loadBoot();
			return next;
		} );

	const canKb = boot ? boot.me.can_kb : false;
	const nav = useMemo( () => {
		const counts = boot ? boot.counts : {};
		const items = [
			{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-helpdesk' ), icon: 'dashboard' },
			{ id: 'tickets', label: __( 'Tickets', 'dev3-ai-helpdesk' ), icon: 'inbox', badge: counts.active ? counts.active : undefined },
		];
		if ( canKb ) {
			items.push( { id: 'kb', label: __( 'Knowledge base', 'dev3-ai-helpdesk' ), icon: 'file' } );
		}
		if ( manager ) {
			items.push(
				{ id: 'canned', label: __( 'Saved replies', 'dev3-ai-helpdesk' ), icon: 'copy' },
				{ id: 'reports', label: __( 'Reports', 'dev3-ai-helpdesk' ), icon: 'chart' },
				{ id: 'settings', label: __( 'Settings', 'dev3-ai-helpdesk' ), icon: 'settings' },
				{ id: 'api', label: __( 'API Config', 'dev3-ai-helpdesk' ), icon: 'key' },
				{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-helpdesk' ), icon: 'bolt' }
			);
		}
		return items;
	}, [ boot, canKb, manager ] );

	const allowed = nav.map( ( n ) => n.id );
	const id = allowed.includes( route.id ) ? route.id : 'dashboard';
	const ready = boot && ( ! manager || settings );
	const refresh = () => {
		loadBoot();
		setMeter( ( n ) => n + 1 );
	};

	const screens = {
		dashboard: () => <Dashboard navigate={ navigate } />,
		tickets: () =>
			route.params[ 0 ] ? (
				<TicketDetail key={ route.params[ 0 ] } id={ parseInt( route.params[ 0 ], 10 ) } navigate={ navigate } onChange={ refresh } />
			) : (
				<Tickets navigate={ navigate } onChange={ refresh } />
			),
		kb: () => <Knowledge navigate={ navigate } onUsage={ refresh } />,
		canned: () => <Canned onChange={ loadBoot } />,
		reports: () => <Reports />,
		settings: () => <SettingsTab settings={ settings } save={ save } section={ route.params[ 0 ] } navigate={ navigate } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-helpdesk' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-helpdesk' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-helpdesk' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-helpdesk' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<BootContext.Provider value={ { ...( boot || {} ), refresh, meter } }>
			<AppShell
				name={ config.name || 'DEV3 AI Helpdesk & Ticket Triage for WordPress' }
				version={ config.version }
				icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
				nav={ nav }
				active={ id }
				actions={ <UsageMeter compact refreshKey={ meter } /> }
				footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-helpdesk' ) }
			>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				{ ! ready && ! error && <SkeletonCard lines={ 5 } /> }
				{ ready && screens[ id ]() }
			</AppShell>
		</BootContext.Provider>
	);
}
