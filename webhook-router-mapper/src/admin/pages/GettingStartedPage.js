import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';

const FEATURES = [
	{
		icon: '🔗',
		title: 'Routes — Ingest',
		desc: 'Register REST endpoints that accept webhooks from any provider. Supports IP allowlisting, rate limiting, and signature verification.',
		tips: [
			'Set ip_allowlist to restrict which IPs can send webhooks',
			'Use rate_limit to throttle incoming traffic',
			'Set auth_token for Bearer token verification',
		],
	},
	{
		icon: '📥',
		title: 'Captures — Inspect',
		desc: 'Every incoming payload is stored for inspection, replay, and debugging. Signature status (verified/failed/skipped) is recorded per request.',
		tips: [
			'Click Inspect to view the full payload and available merge tag paths',
			'Use Apply Mapping to test a mapping synchronously',
			'Edit & Resubmit to modify a payload before replaying',
		],
	},
	{
		icon: '🔀',
		title: 'Mappings — Transform',
		desc: 'Map payload fields to WordPress post fields, meta, or taxonomies. Chain actions: webhooks, emails, SMS, functions, and sub-mappings.',
		tips: [
			'Use {{payload.field}} merge tags in chain templates',
			'Add conditions to chain steps for conditional execution',
			'Set dead_letter_route in mapping config for failed job fallback',
		],
	},
	{
		icon: '⚙',
		title: 'Jobs — Dispatch',
		desc: 'Async job queue processes mappings in the background. Automatic retry with exponential backoff. Bulk retry failed jobs.',
		tips: [
			'Enable Auto-refresh to watch jobs process in real time',
			'Dead jobs are dispatched to dead_letter_route if configured',
			'Click an error cell to expand the full error message',
		],
	},
	{
		icon: '📅',
		title: 'Schedules — Automate',
		desc: 'Timer-driven or URL-triggered mapping runs. Fetch data from external URLs and process through a mapping on a schedule.',
		tips: [
			'Use trigger_token for secure external URL triggers',
			'Set source_url to fetch payload from an external API',
			'Set interval_key to daily/hourly for recurring runs',
		],
	},
	{
		icon: '✉',
		title: 'Messages — Track',
		desc: 'Every email, SMS, and WhatsApp message sent through chains is tracked for opens, clicks, bounces, and delivery status.',
		tips: [
			'Add track: true to email chain config to enable open/click tracking',
			'View the event timeline per message to see delivery history',
			'Filter by channel or status to analyze deliverability',
		],
	},
	{
		icon: '📊',
		title: 'Observability — Monitor',
		desc: 'Per-route hourly metrics, live log tail, signature verification tracking, and gateway block counting.',
		tips: [
			'Toggle Live Tail in the Logs tab for a real-time stream',
			'Dashboard sparklines show captures per hour per route',
			'Sig Verified/Failed stats show signature validation health',
		],
	},
];

const PIPELINE_STEPS = [
	{ icon: '🔗', label: 'Ingest', sub: 'Routes' },
	{ icon: '→', label: '', sub: '' },
	{ icon: '📥', label: 'Capture', sub: 'Inspect' },
	{ icon: '→', label: '', sub: '' },
	{ icon: '🔀', label: 'Transform', sub: 'Mappings' },
	{ icon: '→', label: '', sub: '' },
	{ icon: '⚙', label: 'Dispatch', sub: 'Jobs/Chains' },
	{ icon: '→', label: '', sub: '' },
	{ icon: '📊', label: 'Observe', sub: 'Logs/Metrics' },
];

export default function GettingStartedPage() {
	const [ seeding, setSeeding ] = useState( false );
	const [ seedResult, setSeedResult ] = useState( null );
	const [ expanded, setExpanded ] = useState( null );

	const handleSeed = () => {
		setSeeding( true );
		setSeedResult( null );
		apiFetch( { path: '/wrm/v1/demo/seed', method: 'POST' } )
			.then( ( r ) => {
				setSeedResult( {
					ok: true,
					msg: r.message || 'Demo data seeded successfully.',
				} );
				setSeeding( false );
			} )
			.catch( ( e ) => {
				setSeedResult( {
					ok: false,
					msg: e.message || 'Seeding failed.',
				} );
				setSeeding( false );
			} );
	};

	return (
		<div
			className="wrm-page wrm-getting-started"
			style={ { maxWidth: 900 } }
		>
			{ /* Hero */ }
			<div
				style={ {
					background:
						'linear-gradient(135deg, #1d2327 0%, #2271b1 100%)',
					borderRadius: 8,
					padding: '32px 40px',
					marginBottom: 32,
					color: '#fff',
				} }
			>
				<h2
					style={ { color: '#fff', fontSize: 22, margin: '0 0 8px' } }
				>
					Webhook Router &amp; Mapper
				</h2>
				<p
					style={ {
						margin: '0 0 20px',
						opacity: 0.85,
						fontSize: 14,
					} }
				>
					A WordPress-native API gateway and ETL pipeline — ingest
					webhooks, transform payloads, dispatch actions, and track
					outcomes.
				</p>
				{ /* Pipeline diagram */ }
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: 8,
						flexWrap: 'wrap',
						fontSize: 13,
					} }
				>
					{ PIPELINE_STEPS.map( ( step, i ) =>
						step.label ? (
							<div
								key={ i }
								style={ {
									background: 'rgba(255,255,255,0.12)',
									borderRadius: 6,
									padding: '8px 14px',
									textAlign: 'center',
									minWidth: 80,
								} }
							>
								<div style={ { fontSize: 18 } }>
									{ step.icon }
								</div>
								<div
									style={ { fontWeight: 700, fontSize: 11 } }
								>
									{ step.label }
								</div>
								<div style={ { opacity: 0.7, fontSize: 10 } }>
									{ step.sub }
								</div>
							</div>
						) : (
							<div
								key={ i }
								style={ { fontSize: 18, opacity: 0.5 } }
							>
								{ step.icon }
							</div>
						)
					) }
				</div>
			</div>

			{ /* Demo data seeder */ }
			<div
				style={ {
					border: '2px dashed #2271b1',
					borderRadius: 8,
					padding: '20px 24px',
					marginBottom: 28,
					background: '#f0f6fc',
				} }
			>
				<h3 style={ { margin: '0 0 6px', fontSize: 15 } }>
					🚀 Quick Start with Demo Data
				</h3>
				<p
					style={ {
						margin: '0 0 14px',
						color: '#555',
						fontSize: 13,
					} }
				>
					Seeds sample routes, mappings, captures, jobs, schedules,
					and messages so you can explore every feature without
					setting up from scratch.
					<br />
					<strong>
						Safe to run on a fresh install — will not overwrite
						existing data.
					</strong>
				</p>
				{ seedResult && (
					<Notice
						status={ seedResult.ok ? 'success' : 'error' }
						isDismissible={ false }
						style={ { marginBottom: 12 } }
					>
						{ seedResult.msg }
					</Notice>
				) }
				<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap' } }>
					<Button
						variant="primary"
						onClick={ handleSeed }
						disabled={ seeding }
					>
						{ seeding ? (
							<>
								<Spinner /> Seeding&hellip;
							</>
						) : (
							'🌱 Seed Demo Data'
						) }
					</Button>
				</div>
			</div>

			{ /* Feature cards */ }
			<h3 style={ { fontSize: 15, marginBottom: 16 } }>Feature Guide</h3>
			<div
				style={ {
					display: 'flex',
					flexDirection: 'column',
					gap: 8,
				} }
			>
				{ FEATURES.map( ( f, i ) => (
					<div
						key={ i }
						style={ {
							border: '1px solid #e2e4e7',
							borderRadius: 6,
							overflow: 'hidden',
						} }
					>
						<button
							onClick={ () =>
								setExpanded( expanded === i ? null : i )
							}
							style={ {
								width: '100%',
								textAlign: 'left',
								padding: '12px 16px',
								background: expanded === i ? '#f0f6fc' : '#fff',
								border: 'none',
								cursor: 'pointer',
								display: 'flex',
								alignItems: 'center',
								gap: 10,
							} }
						>
							<span style={ { fontSize: 20 } }>{ f.icon }</span>
							<div style={ { flex: 1 } }>
								<strong
									style={ {
										fontSize: 13,
										color: '#1d2327',
									} }
								>
									{ f.title }
								</strong>
								<p
									style={ {
										margin: 0,
										fontSize: 12,
										color: '#666',
									} }
								>
									{ f.desc }
								</p>
							</div>
							<span style={ { color: '#999', fontSize: 16 } }>
								{ expanded === i ? '▾' : '▸' }
							</span>
						</button>
						{ expanded === i && (
							<div
								style={ {
									padding: '12px 16px 14px',
									borderTop: '1px solid #e2e4e7',
									background: '#fafafa',
								} }
							>
								<ul
									style={ {
										margin: 0,
										paddingLeft: 18,
										fontSize: 12,
										color: '#444',
									} }
								>
									{ f.tips.map( ( tip, ti ) => (
										<li
											key={ ti }
											style={ { marginBottom: 4 } }
										>
											{ tip }
										</li>
									) ) }
								</ul>
							</div>
						) }
					</div>
				) ) }
			</div>

			<p style={ { marginTop: 24, fontSize: 12, color: '#888' } }>
				Webhook Router &amp; Mapper — PHP/WordPress native. Works with
				SQLite, MySQL, Redis object cache.
			</p>
		</div>
	);
}
