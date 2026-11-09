import type { Config } from 'tailwindcss';
import animate from 'tailwindcss-animate';

const hsl = ( name: string ) => `hsl(var(--${ name }) / <alpha-value>)`;

export default {
	content: [ './src/**/*.{ts,tsx}' ],
	// Scope every utility under the mount node so wp-admin styles are untouched.
	important: '#mbk-app',
	corePlugins: {
		preflight: false,
		// `.container` is emitted unscoped by Tailwind; use max-w-* utilities instead.
		container: false,
	},
	theme: {
		extend: {
			colors: {
				// shadcn/ui semantic tokens.
				background: hsl( 'background' ),
				foreground: hsl( 'foreground' ),
				canvas: hsl( 'canvas' ),
				card: {
					DEFAULT: hsl( 'card' ),
					foreground: hsl( 'card-foreground' ),
				},
				popover: {
					DEFAULT: hsl( 'popover' ),
					foreground: hsl( 'popover-foreground' ),
				},
				primary: {
					DEFAULT: hsl( 'primary' ),
					foreground: hsl( 'primary-foreground' ),
				},
				secondary: {
					DEFAULT: hsl( 'secondary' ),
					foreground: hsl( 'secondary-foreground' ),
				},
				muted: {
					DEFAULT: hsl( 'muted' ),
					foreground: hsl( 'muted-foreground' ),
				},
				destructive: {
					DEFAULT: hsl( 'destructive' ),
					foreground: hsl( 'destructive-foreground' ),
				},
				input: hsl( 'input' ),
				ring: hsl( 'ring' ),
				// Brand tokens (figma-bridge/tokens.json).
				ink: {
					DEFAULT: hsl( 'ink' ),
					soft: hsl( 'ink-soft' ),
				},
				surface: {
					DEFAULT: hsl( 'surface' ),
					muted: hsl( 'surface-muted' ),
				},
				border: {
					DEFAULT: hsl( 'border' ),
					strong: hsl( 'border-strong' ),
				},
				accent: {
					DEFAULT: hsl( 'accent' ),
					hover: hsl( 'accent-hover' ),
					soft: hsl( 'accent-soft' ),
					foreground: hsl( 'accent-foreground' ),
				},
				success: {
					DEFAULT: hsl( 'success' ),
					soft: hsl( 'success-soft' ),
				},
				warning: {
					DEFAULT: hsl( 'warning' ),
					soft: hsl( 'warning-soft' ),
				},
				danger: {
					DEFAULT: hsl( 'danger' ),
					soft: hsl( 'danger-soft' ),
				},
				chart: {
					1: hsl( 'chart-1' ),
					2: hsl( 'chart-2' ),
					3: hsl( 'chart-3' ),
					4: hsl( 'chart-4' ),
					5: hsl( 'chart-5' ),
				},
			},
			borderRadius: {
				xl: 'calc(var(--radius) + 4px)',
				lg: 'var(--radius)',
				md: 'calc(var(--radius) - 4px)',
				sm: 'calc(var(--radius) - 6px)',
			},
			fontSize: {
				'2xs': [ '0.6875rem', { lineHeight: '1rem' } ],
			},
			boxShadow: {
				card: '0 1px 2px 0 rgb(0 0 0 / 0.04)',
				pop: '0 8px 24px -6px rgb(0 0 0 / 0.16), 0 2px 6px -2px rgb(0 0 0 / 0.08)',
			},
		},
	},
	plugins: [ animate ],
} satisfies Config;
