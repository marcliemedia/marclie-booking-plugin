import type { PageProps } from '@/app/routes';

import { EmailEditor } from './EmailEditor';
import { EmailList } from './EmailList';

export default function EmailsPage( { params }: PageProps ) {
	return params.code ? <EmailEditor key={ params.code } code={ params.code } /> : <EmailList />;
}
