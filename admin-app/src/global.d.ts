export interface MbkAdminConfig {
	restRoot: string;
	restNamespace: string;
	nonce: string;
	version: string;
	locale: string;
	adminUrl: string;
}

declare global {
	interface Window {
		mbkAdmin?: MbkAdminConfig;
	}
}
