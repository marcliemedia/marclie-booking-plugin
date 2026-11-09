/** Lazy-loaded (recharts is only fetched when the dashboard renders the chart). */
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { formatPercent, getFormatLocale } from '@/lib/format';

export default function OccupancyChart( { data }: { data: { month: string; pct: number }[] } ) {
	const locale = getFormatLocale();
	const monthFormat = new Intl.DateTimeFormat( locale, { month: 'short' } );
	const longFormat = new Intl.DateTimeFormat( locale, { month: 'long', year: 'numeric' } );
	const rows = data.map( ( item ) => {
		const [ y, m ] = item.month.split( '-' ).map( Number );
		const date = new Date( y, m - 1, 1 );
		return { ...item, label: monthFormat.format( date ), long: longFormat.format( date ) };
	} );

	return (
		<div className="h-[240px] w-full">
			<ResponsiveContainer width="100%" height="100%">
				<BarChart data={ rows } margin={ { top: 8, right: 4, bottom: 0, left: -16 } }>
					<CartesianGrid vertical={ false } stroke="hsl(var(--border))" />
					<XAxis dataKey="label" tickLine={ false } axisLine={ false } tick={ { fontSize: 12, fill: 'hsl(var(--muted-foreground))' } } />
					<YAxis
						domain={ [ 0, 100 ] }
						ticks={ [ 0, 25, 50, 75, 100 ] }
						tickFormatter={ ( value: number ) => formatPercent( value ) }
						tickLine={ false }
						axisLine={ false }
						tick={ { fontSize: 12, fill: 'hsl(var(--muted-foreground))' } }
					/>
					<Tooltip
						cursor={ { fill: 'hsl(var(--surface-muted))' } }
						contentStyle={ {
							borderRadius: 8,
							border: '1px solid hsl(var(--border))',
							boxShadow: '0 8px 24px -6px rgb(0 0 0 / 0.16)',
							fontSize: 13,
						} }
						labelFormatter={ ( _label, payload ) => ( payload?.[ 0 ]?.payload as { long?: string } | undefined )?.long ?? '' }
						formatter={ ( value ) => [ formatPercent( Number( value ), locale, 1 ), '' ] }
						separator=""
					/>
					<Bar dataKey="pct" fill="hsl(var(--chart-1))" radius={ [ 4, 4, 0, 0 ] } maxBarSize={ 36 } />
				</BarChart>
			</ResponsiveContainer>
		</div>
	);
}
