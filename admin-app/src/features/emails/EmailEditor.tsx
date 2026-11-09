import { ArrowCounterClockwise, ArrowLeft, CircleNotch, DotsThree, PaperPlaneTilt, Warning } from '@phosphor-icons/react';
import { __, _n, sprintf } from '@wordpress/i18n';
import * as React from 'react';

import { ApiError } from '@/api/client';
import { useEmailTemplate, useResetEmailTemplate, useSaveEmailTemplate, useToggleEmailTemplate } from '@/api/hooks/emails';
import type { EmailContent, EmailLang, EmailTemplateDetail } from '@/api/types';
import { Link, useNavigationGuard } from '@/app/router';
import { ConfirmDialog } from '@/components/app/ConfirmDialog';
import { ErrorState } from '@/components/app/ErrorState';
import { PageHeader } from '@/components/app/PageHeader';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
	DropdownMenu,
	DropdownMenuContent,
	DropdownMenuItem,
	DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FormField } from '@/features/common/FormField';
import { toastError } from '@/features/common/toastError';
import { formatDateTime } from '@/lib/format';
import { applyFieldErrors } from '@/lib/formErrors';

import { EmailPreviewPane, type PreviewBooking } from './EmailPreviewPane';
import { InsertTagMenu } from './InsertTagMenu';
import { bareTag } from './labels';
import { LazyRichTextEditor, type RichTextHandle } from './LazyRichTextEditor';
import { SendTestDialog } from './SendTestDialog';

const LANGS: EmailLang[] = [ 'hr', 'en' ];
type Field = keyof EmailContent;
type Drafts = Record< EmailLang, EmailContent >;
type FieldErrors = Partial< Record< Field, string > > & { other?: string[] };

const FIELD_KEYS: Field[] = [ 'subject', 'preheader', 'heading', 'body_html' ];

function contentOf( template: EmailTemplateDetail, lang: EmailLang ): EmailContent {
	const { subject, preheader, heading, body_html } = template[ lang ];
	return { subject, preheader, heading, body_html };
}

function sameContent( a: EmailContent, b: EmailContent ): boolean {
	return FIELD_KEYS.every( ( key ) => a[ key ] === b[ key ] );
}

function langName( lang: EmailLang ): string {
	return lang === 'hr' ? __( 'Croatian', 'marclie-booking' ) : __( 'English', 'marclie-booking' );
}

function fieldLabel( field: Field ): string {
	switch ( field ) {
		case 'subject':
			return __( 'Subject', 'marclie-booking' );
		case 'preheader':
			return __( 'Preheader', 'marclie-booking' );
		case 'heading':
			return __( 'Heading', 'marclie-booking' );
		default:
			return __( 'Body', 'marclie-booking' );
	}
}

function BackLink() {
	return (
		<Link to="/emails" className="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-ink-soft">
			<ArrowLeft className="h-4 w-4" aria-hidden="true" />
			{ __( 'All e-mails', 'marclie-booking' ) }
		</Link>
	);
}

export function EmailEditor( { code }: { code: string } ) {
	const template = useEmailTemplate( code );

	if ( template.isPending ) {
		return (
			<div className="flex flex-col gap-4">
				<Skeleton className="h-5 w-32" />
				<Skeleton className="h-10 w-72" />
				<div className="grid gap-5 xl:grid-cols-2">
					<Skeleton className="h-[560px]" />
					<Skeleton className="h-[560px]" />
				</div>
			</div>
		);
	}
	if ( template.isError ) {
		return (
			<>
				<BackLink />
				<ErrorState error={ template.error } onRetry={ () => template.refetch() } />
			</>
		);
	}
	return <Editor template={ template.data } refetch={ template.refetch } />;
}

function Editor( { template, refetch }: { template: EmailTemplateDetail; refetch: () => Promise< { data?: EmailTemplateDetail } > } ) {
	const code = template.code;
	const save = useSaveEmailTemplate( code );
	const reset = useResetEmailTemplate( code );
	const toggle = useToggleEmailTemplate();

	const baseline = React.useMemo< Drafts >( () => ( { hr: contentOf( template, 'hr' ), en: contentOf( template, 'en' ) } ), [ template ] );
	const [ drafts, setDrafts ] = React.useState< Drafts >( baseline );
	const previousBaseline = React.useRef( baseline );
	const [ lang, setLang ] = React.useState< EmailLang >( 'hr' );
	const [ errors, setErrors ] = React.useState< Record< EmailLang, FieldErrors > >( { hr: {}, en: {} } );
	const [ booking, setBooking ] = React.useState< PreviewBooking | null >( null );
	const [ dialog, setDialog ] = React.useState< 'test' | 'reset' | null >( null );
	const [ saving, setSaving ] = React.useState( false );

	// Refetched template: languages without local edits follow the server.
	React.useEffect( () => {
		const previous = previousBaseline.current;
		previousBaseline.current = baseline;
		setDrafts( ( current ) => ( {
			hr: sameContent( current.hr, previous.hr ) ? baseline.hr : current.hr,
			en: sameContent( current.en, previous.en ) ? baseline.en : current.en,
		} ) );
	}, [ baseline ] );

	const dirtyLangs = LANGS.filter( ( l ) => ! sameContent( drafts[ l ], baseline[ l ] ) );
	useNavigationGuard( dirtyLangs.length > 0 );

	const required = React.useMemo( () => template.required_tags.map( bareTag ), [ template.required_tags ] );
	const missingFor = ( l: EmailLang ) => {
		const text = FIELD_KEYS.map( ( key ) => drafts[ l ][ key ] ).join( '\n' );
		return required.filter( ( tag ) => ! text.includes( `{${ tag }}` ) );
	};
	const missing = { hr: missingFor( 'hr' ), en: missingFor( 'en' ) };

	const setField = ( l: EmailLang, field: Field, value: string ) => {
		setDrafts( ( current ) => ( { ...current, [ l ]: { ...current[ l ], [ field ]: value } } ) );
		setErrors( ( current ) => ( current[ l ][ field ] ? { ...current, [ l ]: { ...current[ l ], [ field ]: undefined } } : current ) );
	};

	const submit = async () => {
		const blocked = dirtyLangs.find( ( l ) => missing[ l ].length > 0 );
		if ( blocked ) {
			setLang( blocked );
			toast.error(
				sprintf(
					/* translators: 1: language name, 2: list of merge tags. */
					__( '%1$s: add the required tags before saving: %2$s', 'marclie-booking' ),
					langName( blocked ),
					missing[ blocked ].map( ( tag ) => `{${ tag }}` ).join( ', ' )
				)
			);
			return;
		}
		setSaving( true );
		try {
			for ( const l of dirtyLangs ) {
				try {
					const detail = await save.mutateAsync( { lang: l, content: drafts[ l ] } );
					// Keep the server's sanitised version as both draft and baseline.
					setDrafts( ( current ) => ( { ...current, [ l ]: contentOf( detail, l ) } ) );
					setErrors( ( current ) => ( { ...current, [ l ]: {} } ) );
				} catch ( error ) {
					setLang( l );
					const next: FieldErrors = {};
					applyFieldErrors( error, ( name, { message } ) => {
						if ( ( FIELD_KEYS as string[] ).includes( name ) ) {
							next[ name as Field ] = message;
						} else {
							next.other = [ ...( next.other ?? [] ), message ];
						}
					} );
					if ( error instanceof ApiError && Array.isArray( error.data.missing ) && ! next.body_html ) {
						next.other = [ ...( next.other ?? [] ), error.message ];
					}
					setErrors( ( current ) => ( { ...current, [ l ]: next } ) );
					toastError( error );
					return;
				}
			}
			toast.success( __( 'E-mail template saved.', 'marclie-booking' ) );
		} finally {
			setSaving( false );
		}
	};

	const resetLang = async () => {
		try {
			await reset.mutateAsync( lang );
			const result = await refetch();
			if ( result.data ) {
				const fresh = contentOf( result.data, lang );
				setDrafts( ( current ) => ( { ...current, [ lang ]: fresh } ) );
			}
			setErrors( ( current ) => ( { ...current, [ lang ]: {} } ) );
			toast.success(
				sprintf(
					/* translators: %s: language name. */
					__( '%s version reset to the default text.', 'marclie-booking' ),
					langName( lang )
				)
			);
		} catch ( error ) {
			toastError( error );
			throw error;
		}
	};

	const discard = () => {
		setDrafts( ( current ) => ( { ...current, [ lang ]: baseline[ lang ] } ) );
		setErrors( ( current ) => ( { ...current, [ lang ]: {} } ) );
	};

	return (
		<>
			<BackLink />
			<PageHeader
				title={
					<span className="flex flex-wrap items-center gap-2">
						{ template.label }
						<Badge variant="outline">{ template.recipient === 'guest' ? __( 'Guest', 'marclie-booking' ) : __( 'Admin', 'marclie-booking' ) }</Badge>
						{ ! template.enabled && <Badge variant="warning">{ __( 'Off', 'marclie-booking' ) }</Badge> }
					</span>
				}
				description={ template.description }
				actions={
					<>
						<label className="mr-1 inline-flex items-center gap-2 text-sm text-ink-soft">
							<Switch
								checked={ template.enabled }
								onCheckedChange={ ( enabled ) => toggle.mutate( { code, enabled }, { onError: toastError } ) }
							/>
							{ __( 'Send automatically', 'marclie-booking' ) }
						</label>
						<Button variant="secondary" onClick={ () => setDialog( 'test' ) }>
							<PaperPlaneTilt className="h-4 w-4" aria-hidden="true" />
							{ __( 'Send test e-mail', 'marclie-booking' ) }
						</Button>
						<DropdownMenu>
							<DropdownMenuTrigger asChild>
								<Button variant="outline" size="icon" aria-label={ __( 'More actions', 'marclie-booking' ) }>
									<DotsThree className="h-5 w-5" weight="bold" />
								</Button>
							</DropdownMenuTrigger>
							<DropdownMenuContent align="end" className="w-64">
								<DropdownMenuItem disabled={ ! dirtyLangs.includes( lang ) } onSelect={ discard }>
									<ArrowCounterClockwise />
									{ sprintf(
										/* translators: %s: language code (HR/EN). */
										__( 'Discard unsaved changes (%s)', 'marclie-booking' ),
										lang.toUpperCase()
									) }
								</DropdownMenuItem>
								<DropdownMenuItem destructive disabled={ ! template[ lang ].customized } onSelect={ () => setDialog( 'reset' ) }>
									<ArrowCounterClockwise />
									{ sprintf(
										/* translators: %s: language code (HR/EN). */
										__( 'Reset to default (%s)…', 'marclie-booking' ),
										lang.toUpperCase()
									) }
								</DropdownMenuItem>
							</DropdownMenuContent>
						</DropdownMenu>
						<Button onClick={ submit } disabled={ dirtyLangs.length === 0 || saving }>
							{ saving && <CircleNotch className="h-4 w-4 animate-spin" aria-hidden="true" /> }
							{ saving ? __( 'Saving…', 'marclie-booking' ) : __( 'Save', 'marclie-booking' ) }
						</Button>
					</>
				}
			/>

			<div className="grid items-start gap-5 xl:grid-cols-2">
				<Card className="min-w-0 p-5">
					<Tabs value={ lang } onValueChange={ ( next ) => setLang( next as EmailLang ) }>
						<TabsList>
							{ LANGS.map( ( l ) => (
								<TabsTrigger key={ l } value={ l }>
									{ l.toUpperCase() }
									{ dirtyLangs.includes( l ) && (
										<>
											<span className="h-1.5 w-1.5 rounded-full bg-accent" aria-hidden="true" />
											<span className="sr-only">{ __( 'Unsaved changes', 'marclie-booking' ) }</span>
										</>
									) }
									{ missing[ l ].length > 0 && (
										<>
											<Warning className="h-3.5 w-3.5 text-warning" aria-hidden="true" />
											<span className="sr-only">{ __( 'Missing required tags', 'marclie-booking' ) }</span>
										</>
									) }
								</TabsTrigger>
							) ) }
						</TabsList>
						{ LANGS.map( ( l ) => (
							<TabsContent key={ l } value={ l }>
								<LangFields
									lang={ l }
									template={ template }
									content={ drafts[ l ] }
									errors={ errors[ l ] }
									missing={ missing[ l ] }
									onChange={ ( field, value ) => setField( l, field, value ) }
								/>
							</TabsContent>
						) ) }
					</Tabs>
				</Card>
				<div className="min-w-0 xl:sticky xl:top-4">
					<EmailPreviewPane code={ code } lang={ lang } draft={ drafts[ lang ] } booking={ booking } onBookingChange={ setBooking } />
				</div>
			</div>

			<SendTestDialog
				code={ code }
				open={ dialog === 'test' }
				onOpenChange={ ( open ) => setDialog( open ? 'test' : null ) }
				lang={ lang }
				drafts={ drafts }
				booking={ booking }
			/>
			<ConfirmDialog
				open={ dialog === 'reset' }
				onOpenChange={ ( open ) => setDialog( open ? 'reset' : null ) }
				title={ sprintf(
					/* translators: %s: language name. */
					__( 'Reset the %s version to the default?', 'marclie-booking' ),
					langName( lang )
				) }
				description={ __( 'Your edited text for this language is deleted and the text shipped with the plugin is used again. The other language is not changed.', 'marclie-booking' ) }
				confirmLabel={ __( 'Reset to default', 'marclie-booking' ) }
				variant="destructive"
				onConfirm={ resetLang }
			/>
		</>
	);
}

function LangFields( {
	lang,
	template,
	content,
	errors,
	missing,
	onChange,
}: {
	lang: EmailLang;
	template: EmailTemplateDetail;
	content: EmailContent;
	errors: FieldErrors;
	missing: string[];
	onChange: ( field: Field, value: string ) => void;
} ) {
	const [ target, setTarget ] = React.useState< Field >( 'body_html' );
	const inputs = {
		subject: React.useRef< HTMLInputElement >( null ),
		preheader: React.useRef< HTMLInputElement >( null ),
		heading: React.useRef< HTMLInputElement >( null ),
	};
	const body = React.useRef< RichTextHandle >( null );
	const id = ( field: Field ) => `mbk-email-${ lang }-${ field }`;
	const tags = React.useMemo( () => template.merge_tags.map( bareTag ), [ template.merge_tags ] );
	const linkTags = tags.filter( ( tag ) => tag.endsWith( '_link' ) ).map( ( tag ) => `{${ tag }}` );
	const meta = template[ lang ];

	const insert = ( text: string, isBlock: boolean ) => {
		if ( isBlock || target === 'body_html' ) {
			if ( isBlock ) {
				body.current?.insertBlock( text );
			} else {
				body.current?.insertText( text );
			}
			return;
		}
		const input = inputs[ target ].current;
		if ( ! input ) {
			return;
		}
		const start = input.selectionStart ?? input.value.length;
		const end = input.selectionEnd ?? start;
		onChange( target, input.value.slice( 0, start ) + text + input.value.slice( end ) );
		window.requestAnimationFrame( () => {
			input.focus();
			input.setSelectionRange( start + text.length, start + text.length );
		} );
	};

	const textField = ( field: Exclude< Field, 'body_html' >, hint?: string ) => (
		<FormField id={ id( field ) } label={ fieldLabel( field ) } error={ errors[ field ] } hint={ hint }>
			<Input
				ref={ inputs[ field ] }
				id={ id( field ) }
				value={ content[ field ] }
				aria-invalid={ errors[ field ] ? true : undefined }
				aria-describedby={ errors[ field ] ? `${ id( field ) }-error` : hint ? `${ id( field ) }-hint` : undefined }
				onFocus={ () => setTarget( field ) }
				onChange={ ( event ) => onChange( field, event.target.value ) }
			/>
		</FormField>
	);

	return (
		<div className="flex flex-col gap-4">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<p className="text-xs text-muted-foreground">
					{ meta.customized
						? sprintf(
								/* translators: %s: date and time. */
								__( 'Edited text · saved %s', 'marclie-booking' ),
								formatDateTime( meta.updated_at ?? null )
						  )
						: __( 'Default text shipped with the plugin', 'marclie-booking' ) }
				</p>
				<InsertTagMenu tags={ tags } blocks={ template.blocks } targetLabel={ fieldLabel( target ) } onInsert={ insert } />
			</div>

			{ missing.length > 0 && (
				<Alert variant="warning">
					<Warning className="h-4 w-4" aria-hidden="true" />
					<div>
						<AlertTitle>
							{ _n( 'A required tag is missing', 'Required tags are missing', missing.length, 'marclie-booking' ) }
						</AlertTitle>
						<AlertDescription>
							{ __( 'Add these before saving:', 'marclie-booking' ) }{ ' ' }
							{ missing.map( ( tag ) => (
								<code key={ tag } className="mr-1 rounded bg-surface px-1 font-mono text-2xs">{ `{${ tag }}` }</code>
							) ) }
						</AlertDescription>
					</div>
				</Alert>
			) }
			{ errors.other && errors.other.length > 0 && (
				<Alert variant="danger">
					<AlertDescription>{ errors.other.join( ' ' ) }</AlertDescription>
				</Alert>
			) }

			{ textField( 'subject' ) }
			{ textField( 'preheader', __( 'Short text shown after the subject in the inbox.', 'marclie-booking' ) ) }
			{ textField( 'heading' ) }
			<div className="flex flex-col gap-1.5">
				<span id={ `${ id( 'body_html' ) }-label` } className="text-sm font-medium leading-none text-ink-soft">
					{ fieldLabel( 'body_html' ) }
				</span>
				<LazyRichTextEditor
					ref={ body }
					id={ id( 'body_html' ) }
					aria-labelledby={ `${ id( 'body_html' ) }-label` }
					aria-describedby={ errors.body_html ? `${ id( 'body_html' ) }-error` : undefined }
					invalid={ Boolean( errors.body_html ) }
					value={ content.body_html }
					linkTags={ linkTags }
					onChange={ ( html ) => onChange( 'body_html', html ) }
					onFocus={ () => setTarget( 'body_html' ) }
				/>
				{ errors.body_html && (
					<p id={ `${ id( 'body_html' ) }-error` } className="text-xs text-danger" role="alert">
						{ errors.body_html }
					</p>
				) }
			</div>
			{ template.required_tags.length > 0 && (
				<p className="text-xs text-muted-foreground">
					{ __( 'Required tags:', 'marclie-booking' ) }{ ' ' }
					{ template.required_tags.map( ( tag ) => (
						<code key={ tag } className="mr-1 rounded bg-surface-muted px-1 font-mono text-2xs">{ `{${ bareTag( tag ) }}` }</code>
					) ) }
				</p>
			) }
		</div>
	);
}
