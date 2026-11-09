import * as React from 'react';

import type { RichTextEditorProps, RichTextHandle } from './RichTextEditor';

export type { RichTextHandle } from './RichTextEditor';

const Editor = React.lazy( () => import( /* webpackChunkName: "rich-text" */ './RichTextEditor' ) );

/** TipTap loads in its own chunk on first use; a same-size placeholder shows meanwhile. */
export const LazyRichTextEditor = React.forwardRef< RichTextHandle, RichTextEditorProps >( function LazyRichTextEditor( props, ref ) {
	return (
		<React.Suspense
			fallback={
				<div className="rounded-md border border-input bg-surface" aria-busy="true">
					<div className="h-9 border-b border-border" />
					<div className="min-h-[180px] animate-pulse bg-surface-muted/40" />
				</div>
			}
		>
			<Editor ref={ ref } { ...props } />
		</React.Suspense>
	);
} );
