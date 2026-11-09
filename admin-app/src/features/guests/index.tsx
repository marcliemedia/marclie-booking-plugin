import { GuestProfile } from './GuestProfile';
import { GuestsList } from './GuestsList';

export default function GuestsPage( { params }: { params: Record< string, string > } ) {
	const id = Number( params.id );
	return Number.isInteger( id ) && id > 0 ? <GuestProfile key={ id } id={ id } /> : <GuestsList />;
}
