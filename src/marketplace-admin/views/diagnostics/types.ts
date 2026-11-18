/**
 * Shapes of the diagnostics view's REST answers (`woodev/v1/admin/marketplace/{plugin id}/diagnostics`).
 *
 * @package woodev-plugin-framework
 */

export interface DiagnosticsLine {
	label: string;
	value: string;
	/** Show the value as code (an address the merchant reads, never types). */
	monospace?: boolean;
	/** A link shown after the value. */
	link?: { text: string; url: string };
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
