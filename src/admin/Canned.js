import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, ErrorNotice, Modal, PageHeader, ResponsiveTable, Select, TextField, Textarea, useToast } from '../core3/ui';
import { useBoot } from './util';

const PLACEHOLDERS = [ '{{customer_first}}', '{{customer_name}}', '{{ticket_number}}', '{{subject}}', '{{agent_name}}', '{{portal_link}}', '{{site_name}}' ];

export default function Canned( { onChange } ) {
	const boot = useBoot();
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ edit, setEdit ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ remove, setRemove ] = useState( null );

	const load = () => request( 'canned' ).then( setRows ).catch( setError );
	useEffect( () => {
		load();
	}, [] );

	const save = () => {
		if ( ! edit.title.trim() || ! edit.body.trim() ) {
			toast.error( __( 'Give the reply a title and some text.', 'dev3-ai-helpdesk' ) );
			return;
		}
		setBusy( true );
		request( edit.id ? `canned/${ edit.id }` : 'canned', { method: edit.id ? 'PATCH' : 'POST', data: edit } )
			.then( () => {
				toast.success( __( 'Saved reply stored.', 'dev3-ai-helpdesk' ) );
				setEdit( null );
				load();
				onChange();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const del = () => {
		setBusy( true );
		request( `canned/${ remove.id }`, { method: 'DELETE' } )
			.then( () => {
				setRemove( null );
				load();
				onChange();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};
	const depts = ( boot.departments || [] ).map( ( d ) => ( { value: d.id, label: d.name } ) );
	const deptName = ( id ) => ( depts.find( ( d ) => d.value === id ) || {} ).label;

	return (
		<>
			<PageHeader
				title={ __( 'Saved replies', 'dev3-ai-helpdesk' ) }
				description={ __( 'Answers your team sends often. Agents insert them from the reply box; placeholders fill in for each ticket.', 'dev3-ai-helpdesk' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEdit( { title: '', body: '', department: '' } ) }>
						{ __( 'New saved reply', 'dev3-ai-helpdesk' ) }
					</Button>
				}
			/>
			<Card flush>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					caption={ __( 'Saved replies', 'dev3-ai-helpdesk' ) }
					rows={ rows || [] }
					loading={ ! rows && ! error }
					onRowClick={ ( r ) => setEdit( { ...r } ) }
					columns={ [
						{
							key: 'title',
							label: __( 'Title', 'dev3-ai-helpdesk' ),
							primary: true,
							sortable: true,
							render: ( r ) => (
								<span className="dev3hd-cell-main">
									<strong>{ r.title }</strong>
									<span className="dev3hd-muted dev3hd-clamp">{ r.body }</span>
								</span>
							),
						},
						{ key: 'department', label: __( 'Department', 'dev3-ai-helpdesk' ), render: ( r ) => deptName( r.department ) || __( 'All', 'dev3-ai-helpdesk' ) },
						{ key: 'uses', label: __( 'Used', 'dev3-ai-helpdesk' ), sortable: true, align: 'end' },
					] }
					rowActions={ ( r ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span onClick={ ( e ) => e.stopPropagation() }>
							<Button size="sm" variant="ghost" icon="trash" label={ __( 'Delete', 'dev3-ai-helpdesk' ) } onClick={ () => setRemove( r ) } />
						</span>
					) }
					empty={ { icon: 'copy', title: __( 'No saved replies yet', 'dev3-ai-helpdesk' ), description: __( 'Save the answers you type every day: shipping times, password resets, refund policy.', 'dev3-ai-helpdesk' ) } }
				/>
			</Card>
			<Modal
				open={ !! edit }
				onClose={ () => setEdit( null ) }
				size="lg"
				title={ edit && edit.id ? __( 'Edit saved reply', 'dev3-ai-helpdesk' ) : __( 'New saved reply', 'dev3-ai-helpdesk' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setEdit( null ) }>
							{ __( 'Cancel', 'dev3-ai-helpdesk' ) }
						</Button>
						<Button variant="primary" loading={ busy } onClick={ save }>
							{ __( 'Save', 'dev3-ai-helpdesk' ) }
						</Button>
					</>
				}
			>
				{ edit && (
					<>
						<TextField label={ __( 'Title', 'dev3-ai-helpdesk' ) } required value={ edit.title } onChange={ ( v ) => setEdit( { ...edit, title: v } ) } data-autofocus />
						<Textarea label={ __( 'Reply text', 'dev3-ai-helpdesk' ) } required rows={ 8 } value={ edit.body } onChange={ ( v ) => setEdit( { ...edit, body: v } ) } help={ `${ __( 'Placeholders:', 'dev3-ai-helpdesk' ) } ${ PLACEHOLDERS.join( ' ' ) }` } />
						<Select label={ __( 'Department', 'dev3-ai-helpdesk' ) } placeholder={ __( 'All departments', 'dev3-ai-helpdesk' ) } value={ edit.department } onChange={ ( v ) => setEdit( { ...edit, department: v } ) } options={ depts } />
					</>
				) }
			</Modal>
			<Modal
				open={ !! remove }
				onClose={ () => setRemove( null ) }
				size="sm"
				title={ __( 'Delete this saved reply?', 'dev3-ai-helpdesk' ) }
				description={ remove ? remove.title : '' }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRemove( null ) }>
							{ __( 'Cancel', 'dev3-ai-helpdesk' ) }
						</Button>
						<Button variant="danger" loading={ busy } onClick={ del }>
							{ __( 'Delete', 'dev3-ai-helpdesk' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
