import type { Unit } from '@/api/types';

/** Units grouped by house (`houseSlug`, '' = no house), in the order the houses first appear in `units`. */
export function groupByHouse( units: Unit[] ): { house: string; units: Unit[] }[] {
	const map = new Map< string, Unit[] >();
	for ( const unit of units ) {
		const key = unit.houseSlug ?? '';
		map.set( key, [ ...( map.get( key ) ?? [] ), unit ] );
	}
	return [ ...map ].map( ( [ house, list ] ) => ( { house, units: list } ) );
}
