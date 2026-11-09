/**
 * Limited rich-text editor for e-mail bodies (TipTap): paragraphs, bold, italic,
 * links, bullet / ordered lists and h3 — the same set the server keeps after
 * `wp_kses`. Loaded lazily (see `LazyRichTextEditor`), never part of the initial bundle.
 */
import {
	LinkBreak,
	LinkSimple,
	ListBullets,
	ListNumbers,
	TextB,
	TextHThree,
	TextItalic,
	type Icon,
} from '@phosphor-icons/react';
import { EditorContent, useEditor, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { __ } from '@wordpress/i18n';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface RichTextHandle {
	/** Insert plain text (e.g. a merge tag) at the cursor. */
	insertText: ( text: string ) => void;
	/** Insert text as its own paragraph (dynamic blocks such as `{booking_summary}`). */
	insertBlock: ( text: string ) => void;
	focus: () => void;
}

export interface RichTextEditorProps {
	id?: string;
	value: string;
	onChange: ( html: string ) => void;
	onFocus?: () => void;
	invalid?: boolean;
	'aria-labelledby'?: string;
	'aria-describedby'?: string;
	/** Merge tags offered as link targets (`{manage_link}`, …). */
	linkTags?: string[];
	className?: string;
}

const MERGE_TAG_URL = /^\{[a-z0-9_]+\}$/;

/** TipTap serialises an empty document as `<p></p>`. */
function normalize( html: string ): string {
	return html === '<p></p>' ? '' : html;
}

function ToolbarButton( {
	icon: IconComponent,
	label,
	active,
	disabled,
	onClick,
}: {
	icon: Icon;
	label: string;
	active?: boolean;
	disabled?: boolean;
	onClick: () => void;
} ) {
	return (
		<button
			type="button"
			aria-label={ label }
			title={ label }
			aria-pressed={ active }
			disabled={ disabled }
			// Keep the editor selection: the click must not move focus first.
			onMouseDown={ ( event ) => event.preventDefault() }
			onClick={ onClick }
			className={ cn(
				'inline-flex h-7 w-7 items-center justify-center rounded-md text-ink-soft transition-colors hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40',
				active && 'bg-surface-muted text-ink'
			) }
		>
			<IconComponent className="h-4 w-4" weight={ active ? 'bold' : 'regular' } aria-hidden="true" />
		</button>
	);
}

function LinkPopover( { editor, linkTags }: { editor: Editor; linkTags: string[] } ) {
	const [ open, setOpen ] = React.useState( false );
	const [ url, setUrl ] = React.useState( '' );
	const inputId = React.useId();
	const active = editor.isActive( 'link' );

	const onOpenChange = ( next: boolean ) => {
		if ( next ) {
			setUrl( ( editor.getAttributes( 'link' ).href as string | undefined ) ?? '' );
		}
		setOpen( next );
	};

	const apply = ( href: string ) => {
		const value = href.trim();
		const chain = editor.chain().focus().extendMarkRange( 'link' );
		if ( value === '' ) {
			chain.unsetLink().run();
		} else if ( editor.state.selection.empty && ! active ) {
			// No selected text: insert the address itself as the link text.
			chain.insertContent( { type: 'text', text: value, marks: [ { type: 'link', attrs: { href: value } } ] } ).run();
		} else {
			chain.setLink( { href: value } ).run();
		}
		setOpen( false );
	};

	return (
		<Popover open={ open } onOpenChange={ onOpenChange }>
			<PopoverTrigger asChild>
				<button
					type="button"
					aria-label={ __( 'Link', 'marclie-booking' ) }
					title={ __( 'Link', 'marclie-booking' ) }
					aria-pressed={ active }
					onMouseDown={ ( event ) => event.preventDefault() }
					className={ cn(
						'inline-flex h-7 w-7 items-center justify-center rounded-md text-ink-soft transition-colors hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
						active && 'bg-surface-muted text-ink'
					) }
				>
					<LinkSimple className="h-4 w-4" weight={ active ? 'bold' : 'regular' } aria-hidden="true" />
				</button>
			</PopoverTrigger>
			<PopoverContent align="start" className="w-80">
				<form
					className="flex flex-col gap-2"
					onSubmit={ ( event ) => {
						event.preventDefault();
						apply( url );
					} }
				>
					<Label htmlFor={ inputId }>{ __( 'Link address', 'marclie-booking' ) }</Label>
					<Input
						id={ inputId }
						value={ url }
						autoFocus
						placeholder="https://… / {manage_link}"
						onChange={ ( event ) => setUrl( event.target.value ) }
					/>
					{ linkTags.length > 0 && (
						<div className="flex flex-wrap gap-1">
							{ linkTags.map( ( tag ) => (
								<button
									key={ tag }
									type="button"
									onClick={ () => setUrl( tag ) }
									className="rounded border border-border px-1.5 py-0.5 font-mono text-2xs text-ink-soft hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
								>
									{ tag }
								</button>
							) ) }
						</div>
					) }
					<div className="mt-1 flex justify-end gap-2">
						{ active && (
							<Button type="button" size="sm" variant="ghost" onClick={ () => apply( '' ) }>
								<LinkBreak className="h-4 w-4" aria-hidden="true" />
								{ __( 'Remove link', 'marclie-booking' ) }
							</Button>
						) }
						<Button type="submit" size="sm">
							{ __( 'Apply', 'marclie-booking' ) }
						</Button>
					</div>
				</form>
			</PopoverContent>
		</Popover>
	);
}

const RichTextEditor = React.forwardRef< RichTextHandle, RichTextEditorProps >( function RichTextEditor(
	{ id, value, onChange, onFocus, invalid, linkTags = [], className, ...aria },
	ref
) {
	// Last HTML this editor emitted, so external value changes (reset, save) can be told apart.
	const emitted = React.useRef( value );
	const onChangeRef = React.useRef( onChange );
	const onFocusRef = React.useRef( onFocus );
	onChangeRef.current = onChange;
	onFocusRef.current = onFocus;

	const editor = useEditor( {
		extensions: [
			StarterKit.configure( {
				blockquote: false,
				code: false,
				codeBlock: false,
				horizontalRule: false,
				strike: false,
				underline: false,
				dropcursor: false,
				// Would append an empty paragraph after lists/headings and mark untouched text as changed.
				trailingNode: false,
				heading: { levels: [ 3 ] },
				link: {
					openOnClick: false,
					autolink: false,
					linkOnPaste: true,
					// Only `href` survives the server's wp_kses allowlist.
					HTMLAttributes: { target: null, rel: null, class: null },
					isAllowedUri: ( url, { defaultValidate } ) => MERGE_TAG_URL.test( url ) || defaultValidate( url ),
				},
			} ),
		],
		content: value,
		shouldRerenderOnTransaction: true,
		editorProps: {
			attributes: {
				...( id ? { id } : {} ),
				role: 'textbox',
				'aria-multiline': 'true',
				...( aria[ 'aria-labelledby' ] ? { 'aria-labelledby': aria[ 'aria-labelledby' ] } : {} ),
				...( aria[ 'aria-describedby' ] ? { 'aria-describedby': aria[ 'aria-describedby' ] } : {} ),
				...( invalid ? { 'aria-invalid': 'true' } : {} ),
				class: cn(
					'min-h-[180px] max-h-[480px] overflow-y-auto px-3 py-2 text-sm leading-relaxed text-ink-soft outline-none',
					'[&_p]:my-2 [&_h3]:mb-1 [&_h3]:mt-3 [&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-ink',
					'[&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_li>p]:my-0.5',
					'[&_a]:text-accent [&_a]:underline'
				),
			},
		},
		onUpdate: ( { editor: current } ) => {
			const html = normalize( current.getHTML() );
			if ( html === emitted.current ) {
				return;
			}
			emitted.current = html;
			onChangeRef.current( html );
		},
		onFocus: () => onFocusRef.current?.(),
	} );

	// Value replaced from outside (reset to default, saved/normalised by the server).
	React.useEffect( () => {
		if ( editor && value !== emitted.current ) {
			emitted.current = value;
			editor.commands.setContent( value, { emitUpdate: false } );
		}
	}, [ editor, value ] );

	React.useImperativeHandle(
		ref,
		() => ( {
			insertText: ( text ) => editor?.chain().focus().insertContent( { type: 'text', text } ).run(),
			insertBlock: ( text ) =>
				editor
					?.chain()
					.focus()
					.insertContent( { type: 'paragraph', content: [ { type: 'text', text } ] } )
					.run(),
			focus: () => editor?.commands.focus(),
		} ),
		[ editor ]
	);

	if ( ! editor ) {
		return null;
	}

	return (
		<div
			className={ cn(
				'rounded-md border border-input bg-surface focus-within:border-ink-soft/40 focus-within:ring-2 focus-within:ring-ring/25',
				invalid && 'border-danger',
				className
			) }
		>
			<div role="toolbar" aria-label={ __( 'Formatting', 'marclie-booking' ) } className="flex flex-wrap items-center gap-0.5 border-b border-border px-1.5 py-1">
				<ToolbarButton
					icon={ TextB }
					label={ __( 'Bold', 'marclie-booking' ) }
					active={ editor.isActive( 'bold' ) }
					onClick={ () => editor.chain().focus().toggleBold().run() }
				/>
				<ToolbarButton
					icon={ TextItalic }
					label={ __( 'Italic', 'marclie-booking' ) }
					active={ editor.isActive( 'italic' ) }
					onClick={ () => editor.chain().focus().toggleItalic().run() }
				/>
				<ToolbarButton
					icon={ TextHThree }
					label={ __( 'Subheading', 'marclie-booking' ) }
					active={ editor.isActive( 'heading', { level: 3 } ) }
					onClick={ () => editor.chain().focus().toggleHeading( { level: 3 } ).run() }
				/>
				<span className="mx-1 h-4 w-px bg-border" aria-hidden="true" />
				<ToolbarButton
					icon={ ListBullets }
					label={ __( 'Bulleted list', 'marclie-booking' ) }
					active={ editor.isActive( 'bulletList' ) }
					onClick={ () => editor.chain().focus().toggleBulletList().run() }
				/>
				<ToolbarButton
					icon={ ListNumbers }
					label={ __( 'Numbered list', 'marclie-booking' ) }
					active={ editor.isActive( 'orderedList' ) }
					onClick={ () => editor.chain().focus().toggleOrderedList().run() }
				/>
				<span className="mx-1 h-4 w-px bg-border" aria-hidden="true" />
				<LinkPopover editor={ editor } linkTags={ linkTags } />
			</div>
			<EditorContent editor={ editor } />
		</div>
	);
} );

export default RichTextEditor;
