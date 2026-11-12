/**
 * Shapes of the diagnostics view's REST answers (`woodev/v1/admin/marketplace/{plugin id}/diagnostics`).
 *
 * @package woodev-plugin-framework
 */

export interface DiagnosticsLine {
	label: string;
	value: string;
}

export interface DiagnosticsSection {
	title: string;
	lines: DiagnosticsLine[];
}

export interface DiagnosticsState {
	messages: Record< string, string >;
	logging: { enabled: boolean };
	operational: DiagnosticsSection[];
}

export interface DiagnosticsReport {
	messages: Record< string, string >;
	diagnostic: DiagnosticsSection[];
	report: string;
}
