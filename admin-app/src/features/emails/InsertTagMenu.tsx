import { BracketsCurly } from '@phosphor-icons/react';
import { __, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

import { bareTag, mergeTagLabel } from './labels';

/**
 * Searchable menu of the template's merge tags and dynamic blocks. Picking one calls
 * `onInsert` while the target field still holds its selection (the popover does not
 * return focus to the trigger on close).
 */
export function InsertTagMenu( {
	tags,
	blocks,
	targetLabel,
	onInsert,
}: {
	tags: string[];
	blocks: string[];
	/** Name of the field that receives the tag ("Subject", "Body", …). */
	targetLabel: string;
	onInsert: ( tag: string, isBlock: boolean ) => void;
} ) {
	const [ open, setOpen ] = React.useState( false );

	const pick = ( tag: string, isBlock: boolean ) => {
		setOpen( false );
		onInsert( `{${ bareTag( tag ) }}`, isBlock );
	};

	return (
		<Popover open={ open } onOpenChange={ setOpen }>
			<PopoverTrigger asChild>
				<Button
					type="button"
					size="sm"
					variant="secondary"
					// Keep the caret of the field being edited.
					onMouseDown={ ( event ) => event.preventDefault() }
				>
					<BracketsCurly className="h-4 w-4" aria-hidden="true" />
					{ __( 'Insert tag', 'marclie-booking' ) }
				</Button>
			</PopoverTrigger>
			<PopoverContent align="end" className="w-80 p-0" onCloseAutoFocus={ ( event ) => event.preventDefault() }>
				<Command className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:py-1.5 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group]]:px-1.5">
					<CommandInput placeholder={ __( 'Search tags…', 'marclie-booking' ) } className="h-10" />
					<p className="border-b border-border px-3 py-1.5 text-2xs text-muted-foreground">
						{ sprintf(
							/* translators: %s: form field name, e.g. "Subject". */
							__( 'Inserted at the cursor in: %s', 'marclie-booking' ),
							targetLabel
						) }
					</p>
					<CommandList className="max-h-72 py-1">
						<CommandEmpty>{ __( 'No matching tag.', 'marclie-booking' ) }</CommandEmpty>
						<CommandGroup heading={ __( 'Merge tags', 'marclie-booking' ) }>
							{ tags.map( ( tag ) => (
								<CommandItem key={ tag } value={ `${ bareTag( tag ) } ${ mergeTagLabel( tag ) }` } onSelect={ () => pick( tag, false ) } className="py-1.5">
									<span className="min-w-0 flex-1 truncate">{ mergeTagLabel( tag ) }</span>
									<code className="shrink-0 font-mono text-2xs text-muted-foreground">{ `{${ bareTag( tag ) }}` }</code>
								</CommandItem>
							) ) }
						</CommandGroup>
						{ blocks.length > 0 && (
							<CommandGroup heading={ __( 'Blocks (body only)', 'marclie-booking' ) }>
								{ blocks.map( ( tag ) => (
									<CommandItem key={ tag } value={ `${ bareTag( tag ) } ${ mergeTagLabel( tag ) }` } onSelect={ () => pick( tag, true ) } className="py-1.5">
										<span className="min-w-0 flex-1 truncate">{ mergeTagLabel( tag ) }</span>
										<code className="shrink-0 font-mono text-2xs text-muted-foreground">{ `{${ bareTag( tag ) }}` }</code>
									</CommandItem>
								) ) }
							</CommandGroup>
						) }
					</CommandList>
				</Command>
			</PopoverContent>
		</Popover>
	);
}
