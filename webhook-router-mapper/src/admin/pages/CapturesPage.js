/* global navigator */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	SelectControl,
	Notice,
	Spinner,
	Modal,
	TextareaControl,
} from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import StatusBadge from '../components/StatusBadge';
import JsonTree from '../components/JsonTree';

const PER_PAGE = 20;

function parsePayload( raw ) {
	if ( ! raw ) {
		return {};
	}
	if ( typeof raw === 'object' ) {
		return raw;
	}
	try {
		return JSON.parse( raw );
	} catch {
		return { _raw: String( raw ) };
	}
}

export default function CapturesPage() {
	const [ captures, setCaptures ] = useState( [] );
	const [ routes, setRoutes ] = useState( [] );
	const [ mappings, setMappings ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	// Filters
	const [ filterRoute, setFilterRoute ] = useState( '' );
	const [ filterProvider ] = useState( '' );
	const [ filterMapped, setFilterMapped ] = useState( '' );

	// Pagination
	const [ page, setPage ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );

	// Inspect modal
	const [ inspecting, setInspecting ] = useState( null );
	const [ paths, setPaths ] = useState( [] );
	const [ pathsLoading, setPathsLoading ] = useState( false );

	// Apply (sync)
	const [ applyMappingId, setApplyMappingId ] = useState( '' );
	const [ applyResult, setApplyResult ] = useState( null );
	const [ applying, setApplying ] = useState( false );

	// Replay (async enqueue)
	const [ replayMappingId, setReplayMappingId ] = useState( '' );
	const [ replaying, setReplaying ] = useState( false );
	const [ replayResult, setReplayResult ] = useState( null );

	// Edit & Resubmit
	const [ resubmitOpen, setResubmitOpen ] = useState( false );
	const [ resubmitMappingId, setResubmitMappingId ] = useState( '' );
	const [ resubmitPayload, setResubmitPayload ] = useState( '' );
	const [ resubmitPayloadError, setResubmitPayloadError ] = useState( '' );
	const [ resubmitting, setResubmitting ] = useState( false );
	const [ resubmitResult, setResubmitResult ] = useState( null );

	const loadCaptures = useCallback( () => {
		setLoading( true );
		const params = new URLSearchParams();
		params.set( 'per_page', PER_PAGE );
		params.set( 'page', page );
		if ( filterRoute ) {
			params.set( 'route_slug', filterRoute );
		}
		if ( filterProvider ) {
			params.set( 'provider', filterProvider );
		}
		if ( filterMapped === 'mapped' ) {
			params.set( 'mapped', '1' );
		}
		if ( filterMapped === 'unmapped' ) {
			params.set( 'mapped', '0' );
		}

		apiFetch( { path: `/wrm/v1/captures?${ params.toString() }` } )
			.then( ( data ) => {
				const list = Array.isArray( data ) ? data : data.items || [];
				setCaptures( list );
				setTotal( data.total || list.length );
				setLoading( false );
			} )
			.catch( ( e ) => {
				const msg = e?.message || '';
				setError(
					e?.code === 'rest_forbidden' ||
						/forbidden|not allowed/i.test( msg )
						? 'Access denied — administrator privileges required.'
						: msg || 'Failed to load captures'
				);
				setLoading( false );
			} );
	}, [ page, filterRoute, filterProvider, filterMapped ] );

	useEffect( () => {
		loadCaptures();
		apiFetch( { path: '/wrm/v1/routes' } )
			.then( ( data ) => setRoutes( Array.isArray( data ) ? data : [] ) )
			.catch( () => setRoutes( [] ) );
		apiFetch( { path: '/wrm/v1/mappings' } )
			.then( ( data ) =>
				setMappings( Array.isArray( data ) ? data : [] )
			)
			.catch( () => setMappings( [] ) );
	}, [ loadCaptures ] );

	const showNotice = ( msg, type = 'success' ) => {
		setNotice( { msg, type } );
		setTimeout( () => setNotice( null ), 5000 );
	};

	const handleInspect = ( capture ) => {
		setInspecting( capture );
		setApplyResult( null );
		setApplyMappingId( '' );
		setReplayResult( null );
		setReplayMappingId( '' );
		setResubmitResult( null );
		setPathsLoading( true );
		setPaths( [] );
		apiFetch( { path: `/wrm/v1/captures/${ capture.id }/paths` } )
			.then( ( data ) => {
				setPaths( Array.isArray( data ) ? data : data.paths || [] );
				setPathsLoading( false );
			} )
			.catch( () => {
				setPaths( [] );
				setPathsLoading( false );
			} );
	};

	// Synchronous apply
	const handleApplyMapping = () => {
		if ( ! inspecting || ! applyMappingId ) {
			return;
		}
		setApplying( true );
		setApplyResult( null );
		apiFetch( {
			path: `/wrm/v1/captures/${ inspecting.id }/apply`,
			method: 'POST',
			data: { mapping_id: +applyMappingId },
		} )
			.then( ( result ) => {
				setApplyResult( { ok: true, data: result } );
				setApplying( false );
				loadCaptures();
			} )
			.catch( ( e ) => {
				setApplyResult( {
					ok: false,
					msg: e.message || 'Apply failed',
				} );
				setApplying( false );
			} );
	};

	// Async replay (enqueue job)
	const handleReplay = () => {
		if ( ! inspecting || ! replayMappingId ) {
			return;
		}
		setReplaying( true );
		setReplayResult( null );
		apiFetch( {
			path: `/wrm/v1/captures/${ inspecting.id }/replay`,
			method: 'POST',
			data: { mapping_id: +replayMappingId },
		} )
			.then( ( result ) => {
				setReplayResult( { ok: true, data: result } );
				setReplaying( false );
			} )
			.catch( ( e ) => {
				setReplayResult( {
					ok: false,
					msg: e.message || 'Replay failed',
				} );
				setReplaying( false );
			} );
	};

	// Open edit-and-resubmit modal
	const handleOpenResubmit = () => {
		const pretty = JSON.stringify(
			parsePayload( inspecting?.payload ),
			null,
			2
		);
		setResubmitPayload( pretty );
		setResubmitPayloadError( '' );
		setResubmitMappingId( applyMappingId || replayMappingId || '' );
		setResubmitResult( null );
		setResubmitOpen( true );
	};

	const handleResubmit = () => {
		if ( ! inspecting || ! resubmitMappingId ) {
			return;
		}
		let payload;
		try {
			payload = JSON.parse( resubmitPayload );
			if (
				typeof payload !== 'object' ||
				Array.isArray( payload ) ||
				payload === null
			) {
				throw new Error( 'Must be a JSON object' );
			}
			setResubmitPayloadError( '' );
		} catch ( e ) {
			setResubmitPayloadError( 'Invalid JSON: ' + e.message );
			return;
		}
		setResubmitting( true );
		setResubmitResult( null );
		apiFetch( {
			path: `/wrm/v1/captures/${ inspecting.id }/resubmit`,
			method: 'POST',
			data: { mapping_id: +resubmitMappingId, payload },
		} )
			.then( ( result ) => {
				setResubmitResult( { ok: true, data: result } );
				setResubmitting( false );
				loadCaptures();
			} )
			.catch( ( e ) => {
				setResubmitResult( {
					ok: false,
					msg: e.message || 'Resubmit failed',
				} );
				setResubmitting( false );
			} );
	};

	const copyPath = ( p ) => {
		navigator.clipboard
			?.writeText( `{{${ p }}}` )
			.then( () => showNotice( `Copied {{${ p }}} to clipboard` ) );
	};

	const routeOptions = [
		{ label: 'All Routes', value: '' },
		...routes.map( ( r ) => ( { label: r.slug, value: r.slug } ) ),
	];
	const mappingOptions = [
		{ label: '— Select mapping —', value: '' },
		...mappings.map( ( m ) => ( {
			label: m.title || String( m.id ),
			value: String( m.id ),
		} ) ),
	];
	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );

	return (
		<div className="wrm-page wrm-captures-page">
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.msg }
				</Notice>
			) }

			{ /* Filter bar */ }
			<div
				style={ {
					display: 'flex',
					gap: 16,
					alignItems: 'flex-end',
					marginBottom: 16,
					flexWrap: 'wrap',
				} }
			>
				<SelectControl
					label="Route"
					value={ filterRoute }
					options={ routeOptions }
					onChange={ ( v ) => {
						setFilterRoute( v );
						setPage( 1 );
					} }
					style={ { minWidth: 160 } }
				/>
				<SelectControl
					label="Mapped"
					value={ filterMapped }
					options={ [
						{ label: 'All', value: '' },
						{ label: 'Mapped', value: 'mapped' },
						{ label: 'Unmapped', value: 'unmapped' },
					] }
					onChange={ ( v ) => {
						setFilterMapped( v );
						setPage( 1 );
					} }
				/>
				<Button
					variant="secondary"
					onClick={ () => {
						setPage( 1 );
						loadCaptures();
					} }
				>
					Refresh
				</Button>
			</div>

			{ loading && <Spinner /> }
			{ ! loading && error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! loading && ! error && (
				<>
					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style={ { width: 60 } }>ID</th>
								<th>Route</th>
								<th>Provider</th>
								<th>Method</th>
								<th>Sig</th>
								<th>IP</th>
								<th>Mapped</th>
								<th>Created</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{ captures.length === 0 && (
								<tr>
									<td
										colSpan={ 9 }
										style={ {
											textAlign: 'center',
											color: '#888',
											padding: '20px 0',
										} }
									>
										{ filterRoute ? (
											<>
												No captures for route{ ' ' }
												<strong>{ filterRoute }</strong>
												.
											</>
										) : (
											'No captures yet — POST a webhook to an active route to see it here.'
										) }
									</td>
								</tr>
							) }
							{ captures.map( ( cap ) => {
								let sigBg = '#f0f0f0';
								let sigColor = '#888';
								let sigLabel = '— skip';
								if ( cap.sig_status === 'verified' ) {
									sigBg = '#d7f0e0';
									sigColor = '#0a5227';
									sigLabel = '✓ verified';
								} else if ( cap.sig_status === 'failed' ) {
									sigBg = '#fde8e8';
									sigColor = '#7a0000';
									sigLabel = '✗ failed';
								}
								return (
									<tr key={ cap.id }>
										<td>
											<code>{ cap.id }</code>
										</td>
										<td>
											<code>
												{ cap.route_slug || '—' }
											</code>
										</td>
										<td>{ cap.provider || '—' }</td>
										<td>{ cap.method || '—' }</td>
										<td>
											<span
												style={ {
													padding: '1px 6px',
													borderRadius: 10,
													fontSize: 11,
													fontWeight: 600,
													background: sigBg,
													color: sigColor,
												} }
											>
												{ sigLabel }
											</span>
										</td>
										<td>{ cap.source_ip || '—' }</td>
										<td>
											{ cap.mapped ? (
												<StatusBadge status="done" />
											) : (
												<StatusBadge status="dead" />
											) }
										</td>
										<td style={ { fontSize: 12 } }>
											{ cap.created_at || '—' }
										</td>
										<td>
											<Button
												variant="secondary"
												isSmall
												onClick={ () =>
													handleInspect( cap )
												}
											>
												Inspect
											</Button>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>

					<div
						style={ {
							display: 'flex',
							alignItems: 'center',
							gap: 12,
							marginTop: 12,
						} }
					>
						<Button
							variant="secondary"
							isSmall
							disabled={ page <= 1 }
							onClick={ () => setPage( ( p ) => p - 1 ) }
						>
							&larr; Prev
						</Button>
						<span style={ { fontSize: 13 } }>
							Page { page } of { totalPages } ({ total } total)
						</span>
						<Button
							variant="secondary"
							isSmall
							disabled={ page >= totalPages }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							Next &rarr;
						</Button>
					</div>
				</>
			) }

			{ /* ── Inspect Modal ─────────────────────────────────────────── */ }
			{ inspecting && (
				<Modal
					title={ `Capture #${ inspecting.id } — ${
						inspecting.route_slug || ''
					}` }
					onRequestClose={ () => {
						setInspecting( null );
						setResubmitOpen( false );
					} }
					style={ { maxWidth: 1020, width: '96vw' } }
				>
					<div
						style={ {
							display: 'grid',
							gridTemplateColumns: '1fr 1fr',
							gap: 24,
							minHeight: 320,
						} }
					>
						{ /* Left — Payload + Paths */ }
						<div>
							<h3 style={ { marginTop: 0, fontSize: 14 } }>
								Payload
							</h3>
							<div
								style={ {
									background: '#f8f9fa',
									border: '1px solid #e2e4e7',
									borderRadius: 4,
									padding: 12,
									overflowY: 'auto',
									maxHeight: 380,
									fontFamily: 'monospace',
									fontSize: 12,
								} }
							>
								<JsonTree
									data={ parsePayload( inspecting.payload ) }
								/>
							</div>

							<h4
								style={ {
									fontSize: 13,
									marginBottom: 6,
									marginTop: 14,
								} }
							>
								Available Paths
							</h4>
							{ pathsLoading ? (
								<Spinner />
							) : (
								<div
									style={ {
										maxHeight: 180,
										overflowY: 'auto',
									} }
								>
									{ paths.length === 0 ? (
										<p
											style={ {
												color: '#888',
												fontSize: 12,
											} }
										>
											No paths available.
										</p>
									) : (
										paths.map( ( p ) => (
											<div
												key={ p }
												style={ {
													display: 'flex',
													alignItems: 'center',
													gap: 6,
													marginBottom: 4,
												} }
											>
												<code
													style={ {
														fontSize: 11,
														flex: 1,
													} }
												>
													{ p }
												</code>
												<Button
													variant="link"
													isSmall
													onClick={ () =>
														copyPath( p )
													}
												>
													Copy
												</Button>
											</div>
										) )
									) }
								</div>
							) }
						</div>

						{ /* Right — Actions */ }
						<div
							style={ {
								display: 'flex',
								flexDirection: 'column',
								gap: 24,
							} }
						>
							{ /* 1 — Synchronous Apply */ }
							<div
								style={ {
									padding: 14,
									border: '1px solid #e2e4e7',
									borderRadius: 4,
								} }
							>
								<h3
									style={ {
										marginTop: 0,
										fontSize: 13,
										marginBottom: 10,
									} }
								>
									Apply Mapping{ ' ' }
									<span
										style={ {
											fontWeight: 400,
											color: '#888',
											fontSize: 11,
										} }
									>
										(synchronous)
									</span>
								</h3>
								<SelectControl
									label="Mapping"
									value={ applyMappingId }
									options={ mappingOptions }
									onChange={ ( v ) => {
										setApplyMappingId( v );
										setReplayMappingId( v );
									} }
								/>
								<Button
									variant="primary"
									onClick={ handleApplyMapping }
									disabled={ ! applyMappingId || applying }
									style={ { marginTop: 4 } }
								>
									{ applying ? <Spinner /> : 'Apply Now' }
								</Button>
								{ applyResult && (
									<div style={ { marginTop: 10 } }>
										{ applyResult.ok ? (
											<Notice
												status="success"
												isDismissible={ false }
											>
												Applied — post #
												{ applyResult.data?.post_id ||
													'?' }
											</Notice>
										) : (
											<Notice
												status="error"
												isDismissible={ false }
											>
												{ applyResult.msg }
											</Notice>
										) }
									</div>
								) }
							</div>

							{ /* 2 — Async Replay */ }
							<div
								style={ {
									padding: 14,
									border: '1px solid #e2e4e7',
									borderRadius: 4,
								} }
							>
								<h3
									style={ {
										marginTop: 0,
										fontSize: 13,
										marginBottom: 10,
									} }
								>
									Replay via Job Queue{ ' ' }
									<span
										style={ {
											fontWeight: 400,
											color: '#888',
											fontSize: 11,
										} }
									>
										(async)
									</span>
								</h3>
								<SelectControl
									label="Mapping"
									value={ replayMappingId }
									options={ mappingOptions }
									onChange={ ( v ) => {
										setReplayMappingId( v );
										setApplyMappingId( v );
									} }
								/>
								<Button
									variant="secondary"
									onClick={ handleReplay }
									disabled={ ! replayMappingId || replaying }
									style={ { marginTop: 4 } }
								>
									{ replaying ? (
										<Spinner />
									) : (
										'↻ Enqueue Replay'
									) }
								</Button>
								{ replayResult && (
									<div style={ { marginTop: 10 } }>
										{ replayResult.ok ? (
											<Notice
												status="success"
												isDismissible={ false }
											>
												Job #
												{ replayResult.data?.job_id }{ ' ' }
												queued. Check the Jobs tab.
											</Notice>
										) : (
											<Notice
												status="error"
												isDismissible={ false }
											>
												{ replayResult.msg }
											</Notice>
										) }
									</div>
								) }
							</div>

							{ /* 3 — Edit & Resubmit */ }
							<div
								style={ {
									padding: 14,
									border: '1px solid #e2e4e7',
									borderRadius: 4,
								} }
							>
								<h3
									style={ {
										marginTop: 0,
										fontSize: 13,
										marginBottom: 6,
									} }
								>
									Edit &amp; Resubmit{ ' ' }
									<span
										style={ {
											fontWeight: 400,
											color: '#888',
											fontSize: 11,
										} }
									>
										(new capture + async)
									</span>
								</h3>
								<p
									style={ {
										fontSize: 12,
										color: '#666',
										margin: '0 0 10px',
									} }
								>
									Opens an editor to modify the payload before
									re-running. Stores a new capture so the
									original is preserved.
								</p>
								<Button
									variant="tertiary"
									onClick={ handleOpenResubmit }
								>
									✏ Open Payload Editor
								</Button>
							</div>
						</div>
					</div>

					{ /* Edit & Resubmit inline panel */ }
					{ resubmitOpen && (
						<div
							style={ {
								marginTop: 24,
								padding: 16,
								border: '2px solid #007cba',
								borderRadius: 4,
								background: '#f0f6fc',
							} }
						>
							<h3 style={ { marginTop: 0, fontSize: 14 } }>
								Edit Payload &amp; Resubmit
							</h3>
							<div
								style={ {
									display: 'grid',
									gridTemplateColumns: '1fr auto',
									gap: 16,
									alignItems: 'flex-start',
								} }
							>
								<div>
									<TextareaControl
										label="Payload JSON"
										value={ resubmitPayload }
										onChange={ ( v ) => {
											setResubmitPayload( v );
											setResubmitPayloadError( '' );
										} }
										rows={ 12 }
										style={ {
											fontFamily: 'monospace',
											fontSize: 12,
										} }
									/>
									{ resubmitPayloadError && (
										<p
											style={ {
												color: '#d63638',
												fontSize: 12,
												margin: '4px 0 0',
											} }
										>
											{ resubmitPayloadError }
										</p>
									) }
								</div>
								<div style={ { minWidth: 200 } }>
									<SelectControl
										label="Mapping"
										value={ resubmitMappingId }
										options={ mappingOptions }
										onChange={ setResubmitMappingId }
									/>
									<div
										style={ {
											display: 'flex',
											gap: 8,
											marginTop: 8,
										} }
									>
										<Button
											variant="primary"
											onClick={ handleResubmit }
											disabled={
												! resubmitMappingId ||
												resubmitting
											}
										>
											{ resubmitting ? (
												<Spinner />
											) : (
												'↺ Resubmit'
											) }
										</Button>
										<Button
											variant="tertiary"
											onClick={ () => {
												setResubmitOpen( false );
												setResubmitResult( null );
											} }
										>
											Cancel
										</Button>
									</div>
									{ resubmitResult && (
										<div style={ { marginTop: 10 } }>
											{ resubmitResult.ok ? (
												<Notice
													status="success"
													isDismissible={ false }
												>
													New capture #
													{
														resubmitResult.data
															?.new_capture_id
													}
													, job #
													{
														resubmitResult.data
															?.job_id
													}{ ' ' }
													queued.
												</Notice>
											) : (
												<Notice
													status="error"
													isDismissible={ false }
												>
													{ resubmitResult.msg }
												</Notice>
											) }
										</div>
									) }
								</div>
							</div>
						</div>
					) }
				</Modal>
			) }
		</div>
	);
}
