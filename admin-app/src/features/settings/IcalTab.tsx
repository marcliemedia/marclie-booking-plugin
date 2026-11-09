import {
	ArrowClockwise,
	ArrowsClockwise,
	ArrowSquareOut,
	CalendarBlank,
	CircleNotch,
	Info,
	Key,
	PencilSimple,
	Plus,
	Trash,
	WarningCircle,
} from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import type { IcalFeed, IcalOverview, IcalSyncResult, IcalUnit } from '@/api/hooks/ical';
import {
	ICAL_CHANNELS,
	useDeleteIcalFeed,
	useIcal,
	useRotateIcalExport,
	useSyncAllIcal,
	useSyncIcalFeed,
	useUpdateIcalFeed,
} from '@/api/hooks/ical';
import type { IcalChannel } from '@/api/types';
import { useUnitMap } from '@/api/hooks/bootstrap';
import { useRoute } from '@/app/router';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { ErrorState } from '@/components/app/ErrorState';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Switch } from '@/components/ui/switch';
import { CopyButton } from '@/features/common/CopyButton';
import { channelLabel } from '@/features/common/icalChannels';
import { toastError } from '@/features/common/toastError';
import { formatDateTime, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';

import { IcalFeedDialog, importHint } from './IcalFeedDialog';

type FeedDialogState = { unit: IcalUnit; channel: IcalChannel; feed?: IcalFeed } | null;

function exportHint( channel: IcalChannel ): string {
	switch ( channel ) {
		case 'airbnb':
			return __( 'Paste in Airbnb › Availability › Connect calendars › Import calendar.', 'marclie-booking' );
		case 'booking':
			return __( 'Paste in Booking.com › Rates & Availability › Sync calendars › Import calendar.', 'marclie-booking' );
		default:
			return __( 'For any other portal or calendar app that imports iCal links.', 'marclie-booking' );
	}
}

function warningToast( warnings: string[] ) {
	if ( warnings.includes( 'rrule_unsupported' ) ) {
		toast.warning(
			__( 'Some repeating events use rules the website cannot expand; only their first date was blocked. Check those dates on the channel.', 'marclie-booking' )
		);
	}
}

function syncToast( result: IcalSyncResult ) {
	if ( result.status === 'busy' ) {
		toast.info( __( 'This calendar is already being synced — try again in a moment.', 'marclie-booking' ) );
	} else if ( result.status === 'inactive' ) {
		toast.info( __( 'This calendar link is paused. Turn it on to sync.', 'marclie-booking' ) );
	} else if ( ! result.ok ) {
		toast.error( result.error ?? __( 'Sync failed.', 'marclie-booking' ) );
	} else if ( result.status === 'not_modified' ) {
		toast.success( __( 'No changes since the last sync.', 'marclie-booking' ) );
	} else {
		toast.success(
			sprintf(
				/* translators: %d: number of calendar events. */
				_n( 'Synced — %d upcoming event.', 'Synced — %d upcoming events.', result.events_count, 'marclie-booking' ),
				result.events_count
			)
		);
	}
	if ( result.conflicts > 0 ) {
		toast.warning(
			sprintf(
				/* translators: %d: number of new double bookings. */
				_n(
					'%d new conflict with a website booking — see the dashboard.',
					'%d new conflicts with website bookings — see the dashboard.',
					result.conflicts,
					'marclie-booking'
				),
				result.conflicts
			)
		);
	}
	warningToast( result.warnings ?? [] );
}

function FeedStatus( { feed }: { feed: IcalFeed } ) {
	if ( ! feed.active ) {
		return <Badge variant="muted">{ __( 'Paused', 'marclie-booking' ) }</Badge>;
	}
	if ( feed.last_error ) {
		return (
			<Badge
				variant="danger"
				title={
					feed.fail_since
						? sprintf(
								/* translators: %s: date and time. */
								__( 'Failing since %s', 'marclie-booking' ),
								formatDateTime( feed.fail_since )
						  )
						: undefined
				}
			>
				{ __( 'Error', 'marclie-booking' ) }
			</Badge>
		);
	}
	if ( ! feed.last_synced_at ) {
		return <Badge variant="warning">{ __( 'Not synced yet', 'marclie-booking' ) }</Badge>;
	}
	return <Badge variant="success">{ __( 'OK', 'marclie-booking' ) }</Badge>;
}

function FeedRow( {
	feed,
	onEdit,
	onDelete,
}: {
	feed: IcalFeed;
	onEdit: () => void;
	onDelete: () => void;
} ) {
	const sync = useSyncIcalFeed();
	const update = useUpdateIcalFeed();
	const switchId = `mbk-ical-active-${ feed.id }`;

	const runSync = async () => {
		try {
			syncToast( await sync.mutateAsync( feed.id ) );
		} catch ( e ) {
			toastError( e );
		}
	};

	const toggle = async ( active: boolean ) => {
		try {
			await update.mutateAsync( { id: feed.id, active } );
			toast.success( active ? __( 'Calendar link resumed.', 'marclie-booking' ) : __( 'Calendar link paused.', 'marclie-booking' ) );
		} catch ( e ) {
			toastError( e );
		}
	};

	return (
		<div className="flex flex-col gap-2 rounded-md border border-border bg-surface p-2.5">
			<div className="flex items-center gap-2">
				<Switch
					id={ switchId }
					checked={ feed.active }
					disabled={ update.isPending }
					onCheckedChange={ toggle }
					aria-label={ __( 'Sync this calendar link', 'marclie-booking' ) }
				/>
				<FeedStatus feed={ feed } />
				<span className="ml-auto shrink-0 whitespace-nowrap text-xs text-muted-foreground tabular">
					{ sprintf(
						/* translators: %d: number of calendar events. */
						_n( '%d event', '%d events', feed.events_count, 'marclie-booking' ),
						feed.events_count
					) }
				</span>
			</div>
			<code className="truncate rounded bg-surface-muted px-1.5 py-0.5 text-[11px] text-ink-soft" title={ feed.url ?? feed.url_masked }>
				{ feed.url_masked }
			</code>
			<p className="text-xs text-muted-foreground" title={ feed.last_synced_at ? formatDateTime( feed.last_synced_at ) : undefined }>
				{ feed.last_synced_at
					? sprintf(
							/* translators: %s: relative time, e.g. "5 minutes ago". */
							__( 'Last sync %s', 'marclie-booking' ),
							formatRelative( feed.last_synced_at )
					  )
					: __( 'Never synced', 'marclie-booking' ) }
				{ feed.last_error && feed.last_ok_at && (
					<>
						{ ' · ' }
						{ sprintf(
							/* translators: %s: relative time, e.g. "3 hours ago". */
							__( 'last success %s', 'marclie-booking' ),
							formatRelative( feed.last_ok_at )
						) }
					</>
				) }
			</p>
			{ feed.last_error && (
				<p role="alert" className="flex items-start gap-1.5 text-xs text-danger">
					<WarningCircle className="mt-px h-3.5 w-3.5 shrink-0" weight="fill" aria-hidden="true" />
					<span className="line-clamp-3 break-words">{ feed.last_error }</span>
				</p>
			) }
			<div className="flex flex-wrap items-center gap-1">
				<Button size="sm" variant="secondary" onClick={ runSync } disabled={ sync.isPending || ! feed.active }>
					{ sync.isPending ? (
						<CircleNotch className="h-4 w-4 animate-spin" aria-hidden="true" />
					) : (
						<ArrowClockwise className="h-4 w-4" aria-hidden="true" />
					) }
					{ __( 'Sync now', 'marclie-booking' ) }
				</Button>
				<Button size="icon-sm" variant="ghost" onClick={ onEdit } aria-label={ __( 'Edit link', 'marclie-booking' ) } title={ __( 'Edit link', 'marclie-booking' ) }>
					<PencilSimple className="h-4 w-4" aria-hidden="true" />
				</Button>
				<Button
					size="icon-sm"
					variant="ghost"
					className="text-danger hover:bg-danger-soft"
					onClick={ onDelete }
					aria-label={ __( 'Remove link', 'marclie-booking' ) }
					title={ __( 'Remove link', 'marclie-booking' ) }
				>
					<Trash className="h-4 w-4" aria-hidden="true" />
				</Button>
			</div>
		</div>
	);
}

function ChannelCell( {
	unit,
	channel,
	onAdd,
	onEdit,
	onDelete,
}: {
	unit: IcalUnit;
	channel: IcalChannel;
	onAdd: () => void;
	onEdit: ( feed: IcalFeed ) => void;
	onDelete: ( feed: IcalFeed ) => void;
} ) {
	const feeds = unit.feeds.filter( ( f ) => f.channel === channel );
	const noListing = channel === 'airbnb' && ! unit.airbnb_listing_id;
	const label = channelLabel( channel );

	return (
		<section className="flex min-w-0 flex-col gap-3 rounded-lg bg-surface-muted/50 p-3" aria-label={ `${ unit.title } — ${ label }` }>
			<h4 className="text-[13px] font-semibold text-ink">{ label }</h4>

			<div className="flex flex-col gap-1.5">
				<p className="text-2xs font-medium uppercase tracking-wide text-muted-foreground">
					{ sprintf(
						/* translators: %s: channel name (Airbnb, Booking.com…). */
						__( 'Import from %s', 'marclie-booking' ),
						label
					) }
				</p>
				{ feeds.map( ( feed ) => (
					<FeedRow key={ feed.id } feed={ feed } onEdit={ () => onEdit( feed ) } onDelete={ () => onDelete( feed ) } />
				) ) }
				{ feeds.length === 0 && (
					<p className="text-xs text-muted-foreground">
						{ noListing ? __( 'This unit has no Airbnb listing — nothing to connect unless one is created.', 'marclie-booking' ) : importHint( channel ) }
					</p>
				) }
				{ feeds.length === 0 && (
					<Button size="sm" variant="outline" className="self-start" onClick={ onAdd }>
						<Plus className="h-4 w-4" aria-hidden="true" />
						{ __( 'Add link', 'marclie-booking' ) }
					</Button>
				) }
			</div>

			<div className="flex flex-col gap-1.5">
				<p className="text-2xs font-medium uppercase tracking-wide text-muted-foreground">
					{ sprintf(
						/* translators: %s: channel name (Airbnb, Booking.com…). */
						__( 'Export to %s', 'marclie-booking' ),
						label
					) }
				</p>
				<div className="flex min-w-0 items-center gap-1.5">
					<code className="min-w-0 flex-1 truncate rounded border border-border bg-surface px-1.5 py-1 text-[11px] text-ink-soft" title={ unit.export[ channel ] }>
						{ unit.export[ channel ] }
					</code>
					<CopyButton
						text={ unit.export[ channel ] }
						label={ __( 'Copy', 'marclie-booking' ) }
						aria-label={ sprintf(
							/* translators: 1: channel name, 2: unit name. */
							__( 'Copy export link for %1$s — %2$s', 'marclie-booking' ),
							label,
							unit.title
						) }
						className="shrink-0"
					/>
				</div>
				<p className="text-xs text-muted-foreground">{ exportHint( channel ) }</p>
			</div>
		</section>
	);
}

function UnitSection( {
	unit,
	highlight,
	onFeedDialog,
	onDelete,
	onRotate,
}: {
	unit: IcalUnit;
	highlight: boolean;
	onFeedDialog: ( state: FeedDialogState ) => void;
	onDelete: ( feed: IcalFeed ) => void;
	onRotate: ( unit: IcalUnit ) => void;
} ) {
	const failing = unit.feeds.some( ( f ) => f.active && f.last_error );
	return (
		<li id={ `mbk-ical-unit-${ unit.unit_id }` } className={ cn( 'flex scroll-mt-20 flex-col gap-3 px-4 py-4 sm:px-5', highlight && 'bg-accent-soft/40' ) }>
			<div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
				<h3 className="text-sm font-semibold text-ink">{ unit.title }</h3>
				<span className="font-mono text-xs text-muted-foreground">{ unit.code }</span>
				{ failing && (
					<Badge variant="danger">
						<WarningCircle className="h-3 w-3" weight="fill" aria-hidden="true" />
						{ __( 'Sync error', 'marclie-booking' ) }
					</Badge>
				) }
				<span className="text-xs text-muted-foreground">
					{ unit.airbnb_listing_id ? (
						<>
							{ __( 'Airbnb listing ID', 'marclie-booking' ) }{ ' ' }
							<a
								href={ `https://www.airbnb.com/rooms/${ encodeURIComponent( unit.airbnb_listing_id ) }` }
								target="_blank"
								rel="noreferrer noopener"
								className="inline-flex items-center gap-0.5 font-mono text-ink-soft underline-offset-2 hover:underline"
							>
								{ unit.airbnb_listing_id }
								<ArrowSquareOut className="h-3 w-3" aria-hidden="true" />
								<span className="sr-only">{ __( '(opens in a new tab)', 'marclie-booking' ) }</span>
							</a>
						</>
					) : (
						__( 'No Airbnb listing', 'marclie-booking' )
					) }
				</span>
				<Button size="sm" variant="ghost" className="ml-auto" onClick={ () => onRotate( unit ) }>
					<Key className="h-4 w-4" aria-hidden="true" />
					{ __( 'New export links', 'marclie-booking' ) }
				</Button>
			</div>
			<div className="grid gap-3 lg:grid-cols-3">
				{ ICAL_CHANNELS.map( ( channel ) => (
					<ChannelCell
						key={ channel }
						unit={ unit }
						channel={ channel }
						onAdd={ () => onFeedDialog( { unit, channel } ) }
						onEdit={ ( feed ) => onFeedDialog( { unit, channel, feed } ) }
						onDelete={ onDelete }
					/>
				) ) }
			</div>
		</li>
	);
}

function Guide() {
	return (
		<details className="group rounded-md border border-border text-sm">
			<summary className="flex cursor-pointer items-center gap-2 px-3 py-2 font-medium text-ink marker:content-none">
				<Info className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
				{ __( 'How to connect Airbnb and Booking.com', 'marclie-booking' ) }
			</summary>
			<div className="flex flex-col gap-3 border-t border-border px-3 py-3 text-muted-foreground">
				<ol className="list-decimal space-y-1.5 pl-5">
					<li>
						{ __(
							'Import (OTA → website): in Airbnb (Availability › Connect calendars › Export calendar) or the Booking.com extranet (Rates & Availability › Sync calendars › Export), copy the unit’s link and add it here with “Add link”.',
							'marclie-booking'
						) }
					</li>
					<li>
						{ __(
							'Export (website → OTA): copy this unit’s “Export to Airbnb” / “Export to Booking.com” link and paste it into the same screen of that channel under “Import calendar”. Each channel gets its own link so its own bookings are never sent back to it.',
							'marclie-booking'
						) }
					</li>
					<li>
						{ __(
							'Repeat for every unit. The links are secret: if one leaks, use “New export links” and paste the new link into the channels again.',
							'marclie-booking'
						) }
					</li>
				</ol>
				<div>
					<p className="mb-1 font-medium text-ink">{ __( 'Limitations', 'marclie-booking' ) }</p>
					<ul className="list-disc space-y-1 pl-5">
						<li>
							{ __(
								'iCal only shares busy/free dates — no prices, minimum stay, guest names or messages.',
								'marclie-booking'
							) }
						</li>
						<li>
							{ __(
								'There is always a delay: the website reads the channels every 15 minutes (and right before each website booking), but Airbnb and Booking.com decide themselves how often they read our link — sometimes only every few hours. In peak season consider manual confirmation of OTA bookings.',
								'marclie-booking'
							) }
						</li>
						<li>
							{ __(
								'If an imported booking overlaps a website booking, it is shown in red on the calendar and on the dashboard, and an e-mail is sent. Nothing is cancelled automatically — resolve it with the guest and the channel.',
								'marclie-booking'
							) }
						</li>
					</ul>
				</div>
				<p className="text-xs">
					{ __( 'Full guide (Croatian and English): docs/ical-setup.md in the plugin folder.', 'marclie-booking' ) }
				</p>
			</div>
		</details>
	);
}

function Overview( { data }: { data: IcalOverview } ) {
	const syncAll = useSyncAllIcal();
	const feeds = data.units.flatMap( ( u ) => u.feeds );
	const active = feeds.filter( ( f ) => f.active );
	const failing = active.filter( ( f ) => f.last_error ).length;

	const runSyncAll = async () => {
		try {
			const { results } = await syncAll.mutateAsync();
			const errors = results.filter( ( r ) => r.status === 'error' ).length;
			if ( errors > 0 ) {
				toast.error(
					sprintf(
						/* translators: 1: failed links, 2: all links. */
						__( '%1$d of %2$d calendar links failed to sync.', 'marclie-booking' ),
						errors,
						results.length
					)
				);
			} else {
				toast.success(
					sprintf(
						/* translators: %d: number of calendar links. */
						_n( '%d calendar link synced.', '%d calendar links synced.', results.length, 'marclie-booking' ),
						results.length
					)
				);
			}
			warningToast( results.flatMap( ( r ) => r.warnings ?? [] ) );
		} catch ( e ) {
			toastError( e );
		}
	};

	return (
		<Card>
			<CardHeader className="flex-wrap">
				<div className="flex flex-col gap-1">
					<CardTitle>{ __( 'Calendar sync (iCal)', 'marclie-booking' ) }</CardTitle>
					<CardDescription>
						{ __(
							'Keeps Airbnb, Booking.com and the website from selling the same nights: busy dates are exchanged through secret calendar links.',
							'marclie-booking'
						) }
					</CardDescription>
				</div>
				<Button variant="secondary" onClick={ runSyncAll } disabled={ syncAll.isPending || active.length === 0 } className="shrink-0">
					{ syncAll.isPending ? (
						<CircleNotch className="h-4 w-4 animate-spin" aria-hidden="true" />
					) : (
						<ArrowsClockwise className="h-4 w-4" aria-hidden="true" />
					) }
					{ syncAll.isPending ? __( 'Syncing…', 'marclie-booking' ) : __( 'Sync all', 'marclie-booking' ) }
				</Button>
			</CardHeader>
			<CardContent className="flex flex-col gap-4">
				<dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
					<div>
						<dt className="text-xs text-muted-foreground">{ __( 'Connected links', 'marclie-booking' ) }</dt>
						<dd className="font-semibold tabular text-ink">{ active.length }</dd>
					</div>
					<div>
						<dt className="text-xs text-muted-foreground">{ __( 'With errors', 'marclie-booking' ) }</dt>
						<dd className={ cn( 'font-semibold tabular', failing > 0 ? 'text-danger' : 'text-ink' ) }>{ failing }</dd>
					</div>
					<div>
						<dt className="text-xs text-muted-foreground">{ __( 'Automatic sync', 'marclie-booking' ) }</dt>
						<dd className="text-ink">
							{ sprintf(
								/* translators: %d: minutes. */
								_n( 'every %d minute', 'every %d minutes', data.cron.interval_min, 'marclie-booking' ),
								data.cron.interval_min
							) }
						</dd>
					</div>
					<div>
						<dt className="text-xs text-muted-foreground">{ __( 'Next run', 'marclie-booking' ) }</dt>
						<dd className={ data.cron.next_run ? 'text-ink' : 'text-danger' } title={ data.cron.next_run ? formatDateTime( data.cron.next_run ) : undefined }>
							{ data.cron.next_run ? formatRelative( data.cron.next_run ) : __( 'Not scheduled', 'marclie-booking' ) }
						</dd>
					</div>
				</dl>
				{ feeds.length === 0 && (
					<div className="flex items-start gap-2 rounded-md bg-accent-soft/60 px-3 py-2.5 text-sm text-ink-soft">
						<CalendarBlank className="mt-0.5 h-4 w-4 shrink-0 text-accent" aria-hidden="true" />
						<span>
							{ __(
								'No calendars connected yet. Start with one unit: add its Airbnb and Booking.com export links below, then paste our export links into both channels.',
								'marclie-booking'
							) }
						</span>
					</div>
				) }
				<Guide />
			</CardContent>
		</Card>
	);
}

export function IcalTab() {
	const ical = useIcal();
	const unitMap = useUnitMap();
	const route = useRoute();
	const rotate = useRotateIcalExport();
	const remove = useDeleteIcalFeed();
	const [ feedDialog, setFeedDialog ] = React.useState< FeedDialogState >( null );
	const [ feedDialogOpen, setFeedDialogOpen ] = React.useState( false );
	const [ deleting, setDeleting ] = React.useState< IcalFeed | null >( null );
	const [ rotating, setRotating ] = React.useState< IcalUnit | null >( null );
	const focusUnit = Number( route.query.get( 'unit' ) ) || 0;
	const hasData = ical.data !== undefined;

	React.useEffect( () => {
		if ( focusUnit && hasData ) {
			document.getElementById( `mbk-ical-unit-${ focusUnit }` )?.scrollIntoView( { block: 'start' } );
		}
	}, [ focusUnit, hasData ] );

	const houses = React.useMemo( () => {
		const map = new Map< string, { name: string; units: IcalUnit[] } >();
		( ical.data?.units ?? [] ).forEach( ( unit ) => {
			const group = map.get( unit.house ) ?? { name: unitMap.get( unit.unit_id )?.house ?? unit.house, units: [] };
			group.units.push( unit );
			map.set( unit.house, group );
		} );
		return [ ...map.entries() ];
	}, [ ical.data, unitMap ] );

	if ( ical.isPending ) {
		return (
			<div className="flex flex-col gap-4">
				<Skeleton className="h-40 w-full" />
				<Skeleton className="h-96 w-full" />
			</div>
		);
	}
	if ( ical.isError ) {
		return <ErrorState error={ ical.error } onRetry={ () => ical.refetch() } />;
	}

	const openFeedDialog = ( state: FeedDialogState ) => {
		setFeedDialog( state );
		setFeedDialogOpen( true );
	};

	return (
		<div className="flex flex-col gap-4">
			<Overview data={ ical.data } />

			{ houses.map( ( [ house, { name, units } ] ) => (
				<Card key={ house }>
					<CardHeader className="pb-3">
						<CardTitle>{ name }</CardTitle>
					</CardHeader>
					<ul className="divide-y divide-border border-t border-border">
						{ units.map( ( unit ) => (
							<UnitSection
								key={ unit.unit_id }
								unit={ unit }
								highlight={ unit.unit_id === focusUnit }
								onFeedDialog={ openFeedDialog }
								onDelete={ setDeleting }
								onRotate={ setRotating }
							/>
						) ) }
					</ul>
				</Card>
			) ) }

			{ feedDialog && (
				<IcalFeedDialog
					open={ feedDialogOpen }
					onOpenChange={ setFeedDialogOpen }
					unit={ feedDialog.unit }
					channel={ feedDialog.channel }
					feed={ feedDialog.feed }
				/>
			) }

			<ConfirmDialog
				open={ deleting !== null }
				onOpenChange={ ( open ) => ! open && setDeleting( null ) }
				variant="destructive"
				title={ __( 'Remove this calendar link?', 'marclie-booking' ) }
				description={ __(
					'Dates imported from this link are released on the website immediately. Remember to also remove our export link from the channel if you stop using it.',
					'marclie-booking'
				) }
				confirmLabel={ __( 'Remove link', 'marclie-booking' ) }
				onConfirm={ async () => {
					if ( ! deleting ) {
						return;
					}
					try {
						await remove.mutateAsync( deleting.id );
						toast.success( __( 'Calendar link removed.', 'marclie-booking' ) );
					} catch ( e ) {
						toastError( e );
						throw e;
					}
				} }
			/>

			<ConfirmDialog
				open={ rotating !== null }
				onOpenChange={ ( open ) => ! open && setRotating( null ) }
				variant="destructive"
				title={
					rotating
						? sprintf(
								/* translators: %s: unit name. */
								__( 'Create new export links for %s?', 'marclie-booking' ),
								rotating.title
						  )
						: ''
				}
				description={ __(
					'The current links for Airbnb, Booking.com and other calendars stop working immediately. Until you paste the new links into every channel, they will not see new website bookings for this unit — risk of double bookings.',
					'marclie-booking'
				) }
				confirmLabel={ __( 'Create new links', 'marclie-booking' ) }
				onConfirm={ async () => {
					if ( ! rotating ) {
						return;
					}
					try {
						await rotate.mutateAsync( rotating.unit_id );
						toast.success( __( 'New export links created. Paste them into Airbnb and Booking.com now.', 'marclie-booking' ) );
					} catch ( e ) {
						toastError( e );
						throw e;
					}
				} }
			/>
		</div>
	);
}
