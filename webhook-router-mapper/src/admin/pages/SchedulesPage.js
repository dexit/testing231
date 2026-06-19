/* global navigator */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	Notice,
	Spinner,
	Modal,
} from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';

const INTERVALS = [
	{ label: 'Manual / URL trigger only', value: 'manual' },
	{ label: 'Every minute', value: 'every_minute' },
	{ label: 'Every 5 minutes', value: 'every_5_minutes' },
	{ label: 'Every 15 minutes', value: 'every_15_minutes' },
	{ label: 'Every 30 minutes', value: 'every_30_minutes' },
	{ label: 'Hourly', value: 'hourly' },
	{ label: 'Twice daily', value: 'twicedaily' },
	{ label: 'Daily', value: 'daily' },
	{ label: 'Weekly', value: 'weekly' },
];

const EMPTY = {
	label: '',
	mapping_id: '',
	interval_key: 'manual',
	run_mode: 'async',
	seed_payload: '',
	source_url: '',
	source_method: 'GET',
};

export default function SchedulesPage() {
	const [ schedules, setSchedules ] = useState( [] );
	const [ mappings, setMappings ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ form, setForm ] = useState( EMPTY );
	const [ saving, setSaving ] = useState( false );
	const [ payloadErr, setPayloadErr ] = useState( '' );
	const [ editTarget, setEditTarget ] = useState( null );
	const [ editForm, setEditForm ] = useState( {} );
	const [ editSaving, setEditSaving ] = useState( false );
	const [ editPayloadErr, setEditPayloadErr ] = useState( '' );

	const load = useCallback( () => {
		setLoading( true );
		apiFetch( { path: '/wrm/v1/schedules' } )
			.then( ( data ) => {
				setSchedules( Array.isArray( data ) ? data : [] );
				setLoading( false );
			} )
			.catch( ( e ) => {
				setError( e.message || 'Failed to load schedules' );
				setLoading( false );
			} );
	}, [] );

	useEffect( () => {
		load();
		apiFetch( { path: '/wrm/v1/mappings' } )
			.then( ( data ) =>
				setMappings( Array.isArray( data ) ? data : [] )
			)
			.catch( () => setMappings( [] ) );
	}, [ load ] );

	const showNotice = ( msg, type = 'success' ) => {
		setNotice( { msg, type } );
		setTimeout( () => setNotice( null ), 5000 );
	};

	const set = ( k, v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );

	const handleCreate = () => {
		if ( ! form.mapping_id ) {
			showNotice( 'Pick a mapping first.', 'error' );
			return;
		}
		if ( form.seed_payload.trim() ) {
			try {
				JSON.parse( form.seed_payload );
				setPayloadErr( '' );
			} catch ( e ) {
				setPayloadErr( 'Seed payload is not valid JSON: ' + e.message );
				return;
			}
		}
		setSaving( true );
		apiFetch( {
			path: '/wrm/v1/schedules',
			method: 'POST',
			data: { ...form, mapping_id: +form.mapping_id },
		} )
			.then( () => {
				showNotice( 'Schedule created.' );
				setForm( EMPTY );
				setSaving( false );
				load();
			} )
			.catch( ( e ) => {
				showNotice( e.message || 'Create failed', 'error' );
				setSaving( false );
			} );
	};

	const runNow = ( id ) => {
		apiFetch( { path: `/wrm/v1/schedules/${ id }/run`, method: 'POST' } )
			.then( ( r ) => {
				showNotice(
					`Ran schedule #${ id } — ${ r.status }${
						r.job_id ? ` (job #${ r.job_id })` : ''
					}.`
				);
				load();
			} )
			.catch( ( e ) => showNotice( e.message || 'Run failed', 'error' ) );
	};

	const toggleStatus = ( s ) => {
		const status = s.status === 'active' ? 'paused' : 'active';
		apiFetch( {
			path: `/wrm/v1/schedules/${ s.id }`,
			method: 'PUT',
			data: { status },
		} )
			.then( () => {
				showNotice( `Schedule #${ s.id } ${ status }.` );
				load();
			} )
			.catch( ( e ) =>
				showNotice( e.message || 'Update failed', 'error' )
			);
	};

	const remove = ( id ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( `Delete schedule #${ id }?` ) ) {
			return;
		}
		apiFetch( { path: `/wrm/v1/schedules/${ id }`, method: 'DELETE' } )
			.then( () => {
				showNotice( `Schedule #${ id } deleted.` );
				load();
			} )
			.catch( ( e ) =>
				showNotice( e.message || 'Delete failed', 'error' )
			);
	};

	const copyTrigger = ( url ) => {
		navigator.clipboard
			?.writeText( url )
			.then( () => showNotice( 'Trigger URL copied to clipboard.' ) );
	};

	const openEdit = ( s ) => {
		setEditForm( {
			label: s.label || '',
			mapping_id: s.mapping_id ? String( s.mapping_id ) : '',
			interval_key: s.interval_key || 'manual',
			run_mode: s.run_mode || 'async',
			seed_payload: s.seed_payload || '',
			source_url: s.source_url || '',
			source_method: s.source_method || 'GET',
		} );
		setEditPayloadErr( '' );
		setEditTarget( s );
	};

	const handleEditSave = () => {
		if ( ! editForm.mapping_id ) {
			showNotice( 'Pick a mapping first.', 'error' );
			return;
		}
		if ( editForm.seed_payload.trim() ) {
			try {
				JSON.parse( editForm.seed_payload );
				setEditPayloadErr( '' );
			} catch ( e ) {
				setEditPayloadErr(
					'Seed payload is not valid JSON: ' + e.message
				);
				return;
			}
		}
		setEditSaving( true );
		apiFetch( {
			path: `/wrm/v1/schedules/${ editTarget.id }`,
			method: 'PUT',
			data: { ...editForm, mapping_id: +editForm.mapping_id },
		} )
			.then( () => {
				showNotice( 'Schedule updated.' );
				setEditTarget( null );
				setEditSaving( false );
				load();
			} )
			.catch( ( e ) => {
				showNotice( e.message || 'Update failed', 'error' );
				setEditSaving( false );
			} );
	};

	const mappingOptions = [
		{ label: '— Select mapping —', value: '' },
		...mappings.map( ( m ) => ( {
			label: m.title || String( m.id ),
			value: String( m.id ),
		} ) ),
	];
	const mappingName = ( id ) =>
		mappings.find( ( m ) => String( m.id ) === String( id ) )?.title ||
		`#${ id }`;

	return (
		<div className="wrm-page wrm-schedules-page">
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.msg }
				</Notice>
			) }

			{ /* Create form */ }
			<div
				style={ {
					padding: 16,
					border: '1px solid #e2e4e7',
					borderRadius: 6,
					marginBottom: 24,
					background: '#fff',
				} }
			>
				<h2 style={ { marginTop: 0, fontSize: 15 } }>New Schedule</h2>
				<div
					style={ {
						display: 'grid',
						gridTemplateColumns: '1fr 1fr 1fr',
						gap: 16,
					} }
				>
					<TextControl
						label="Label"
						value={ form.label }
						onChange={ ( v ) => set( 'label', v ) }
						placeholder="Nightly CRM sync"
					/>
					<SelectControl
						label="Mapping"
						value={ form.mapping_id }
						options={ mappingOptions }
						onChange={ ( v ) => set( 'mapping_id', v ) }
					/>
					<SelectControl
						label="Interval (timer)"
						value={ form.interval_key }
						options={ INTERVALS }
						onChange={ ( v ) => set( 'interval_key', v ) }
					/>
					<SelectControl
						label="Run mode"
						value={ form.run_mode }
						options={ [
							{ label: 'Async (queue)', value: 'async' },
							{ label: 'Sync (immediate)', value: 'sync' },
						] }
						onChange={ ( v ) => set( 'run_mode', v ) }
					/>
					<TextControl
						label="Source URL (optional ETL pull)"
						value={ form.source_url }
						onChange={ ( v ) => set( 'source_url', v ) }
						placeholder="https://api.example.com/data.json"
					/>
					<SelectControl
						label="Source method"
						value={ form.source_method }
						options={ [
							{ label: 'GET', value: 'GET' },
							{ label: 'POST', value: 'POST' },
						] }
						onChange={ ( v ) => set( 'source_method', v ) }
					/>
				</div>
				<div style={ { marginTop: 12 } }>
					<TextareaControl
						label="Seed payload JSON (used when no Source URL is set)"
						value={ form.seed_payload }
						onChange={ ( v ) => {
							set( 'seed_payload', v );
							setPayloadErr( '' );
						} }
						rows={ 4 }
						placeholder='{ "type": "daily_digest" }'
					/>
					{ payloadErr && (
						<p style={ { color: '#d63638', fontSize: 12 } }>
							{ payloadErr }
						</p>
					) }
				</div>
				<Button
					variant="primary"
					onClick={ handleCreate }
					disabled={ saving }
					style={ { marginTop: 8 } }
				>
					{ saving ? <Spinner /> : 'Create Schedule' }
				</Button>
			</div>

			{ loading && <Spinner /> }
			{ ! loading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! loading && ! error && (
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style={ { width: 50 } }>ID</th>
							<th>Label</th>
							<th>Mapping</th>
							<th>Interval</th>
							<th>Mode</th>
							<th>Status</th>
							<th>Last run</th>
							<th>Next run</th>
							<th>Trigger URL</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						{ schedules.length === 0 && (
							<tr>
								<td
									colSpan={ 10 }
									style={ {
										textAlign: 'center',
										color: '#888',
									} }
								>
									No schedules yet.
								</td>
							</tr>
						) }
						{ schedules.map( ( s ) => (
							<tr key={ s.id }>
								<td>
									<code>{ s.id }</code>
								</td>
								<td>
									{ s.label || (
										<em style={ { color: '#999' } }>
											(untitled)
										</em>
									) }
								</td>
								<td>{ mappingName( s.mapping_id ) }</td>
								<td>
									<code>{ s.interval_key }</code>
								</td>
								<td>{ s.run_mode }</td>
								<td>
									<StatusBadge
										status={
											s.status === 'active'
												? 'done'
												: 'paused'
										}
									/>
								</td>
								<td style={ { fontSize: 12 } }>
									{ s.last_run || '—' }
								</td>
								<td style={ { fontSize: 12 } }>
									{ s.next_run || '—' }
								</td>
								<td>
									{ s.trigger_url ? (
										<Button
											variant="link"
											isSmall
											onClick={ () =>
												copyTrigger( s.trigger_url )
											}
										>
											Copy URL
										</Button>
									) : (
										'—'
									) }
								</td>
								<td style={ { whiteSpace: 'nowrap' } }>
									<Button
										variant="secondary"
										isSmall
										onClick={ () => runNow( s.id ) }
									>
										▶ Run
									</Button>{ ' ' }
									<Button
										variant="tertiary"
										isSmall
										onClick={ () => toggleStatus( s ) }
									>
										{ s.status === 'active'
											? 'Pause'
											: 'Resume' }
									</Button>{ ' ' }
									<Button
										variant="secondary"
										isSmall
										onClick={ () => openEdit( s ) }
									>
										Edit
									</Button>{ ' ' }
									<Button
										variant="link"
										isSmall
										isDestructive
										onClick={ () => remove( s.id ) }
									>
										Delete
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ editTarget && (
				<Modal
					title={ `Edit Schedule #${ editTarget.id }` }
					onRequestClose={ () => setEditTarget( null ) }
					style={ { maxWidth: 720 } }
				>
					<div
						style={ {
							display: 'grid',
							gridTemplateColumns: '1fr 1fr 1fr',
							gap: 16,
						} }
					>
						<TextControl
							label="Label"
							value={ editForm.label }
							onChange={ ( v ) =>
								setEditForm( ( f ) => ( { ...f, label: v } ) )
							}
							placeholder="Nightly CRM sync"
						/>
						<SelectControl
							label="Mapping"
							value={ editForm.mapping_id }
							options={ mappingOptions }
							onChange={ ( v ) =>
								setEditForm( ( f ) => ( {
									...f,
									mapping_id: v,
								} ) )
							}
						/>
						<SelectControl
							label="Interval (timer)"
							value={ editForm.interval_key }
							options={ INTERVALS }
							onChange={ ( v ) =>
								setEditForm( ( f ) => ( {
									...f,
									interval_key: v,
								} ) )
							}
						/>
						<SelectControl
							label="Run mode"
							value={ editForm.run_mode }
							options={ [
								{ label: 'Async (queue)', value: 'async' },
								{ label: 'Sync (immediate)', value: 'sync' },
							] }
							onChange={ ( v ) =>
								setEditForm( ( f ) => ( {
									...f,
									run_mode: v,
								} ) )
							}
						/>
						<TextControl
							label="Source URL (optional ETL pull)"
							value={ editForm.source_url }
							onChange={ ( v ) =>
								setEditForm( ( f ) => ( {
									...f,
									source_url: v,
								} ) )
							}
							placeholder="https://api.example.com/data.json"
						/>
						<SelectControl
							label="Source method"
							value={ editForm.source_method }
							options={ [
								{ label: 'GET', value: 'GET' },
								{ label: 'POST', value: 'POST' },
							] }
							onChange={ ( v ) =>
								setEditForm( ( f ) => ( {
									...f,
									source_method: v,
								} ) )
							}
						/>
					</div>
					<div style={ { marginTop: 12 } }>
						<TextareaControl
							label="Seed payload JSON (used when no Source URL is set)"
							value={ editForm.seed_payload }
							onChange={ ( v ) => {
								setEditForm( ( f ) => ( {
									...f,
									seed_payload: v,
								} ) );
								setEditPayloadErr( '' );
							} }
							rows={ 4 }
							placeholder='{ "type": "daily_digest" }'
						/>
						{ editPayloadErr && (
							<p style={ { color: '#d63638', fontSize: 12 } }>
								{ editPayloadErr }
							</p>
						) }
					</div>
					<div
						style={ {
							display: 'flex',
							gap: 8,
							justifyContent: 'flex-end',
							marginTop: 8,
						} }
					>
						<Button
							variant="secondary"
							onClick={ () => setEditTarget( null ) }
						>
							Cancel
						</Button>
						<Button
							variant="primary"
							onClick={ handleEditSave }
							disabled={ editSaving }
						>
							{ editSaving ? <Spinner /> : 'Save Changes' }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
