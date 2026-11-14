/**
 * Shapes of the tools view's REST answers (`woodev/v1/admin/marketplace/{plugin id}/tools`).
 *
 * @package woodev-plugin-framework
 */

export interface ToolAccount {
	id: number;
	name: string;
}

export interface ToolRow {
	id: string;
	title: string;
	description: string;
	button: string;
	takes_claim: boolean;
	disabled: boolean;
	disabled_reason: string;
}

/** The account line: whether a background run holds the account claim. */
export interface ToolActivity {
	active: boolean;
	text: string;
	blocked_actions: string[];
	observed_at: string;
}

export interface ToolsState {
	messages: Record< string, string >;
	accounts: ToolAccount[];
	tools: ToolRow[];
	activity: ToolActivity;
}

export interface ToolPreview {
	tool: string;
	summary: string;
	disabled: boolean;
	disabled_reason: string;
	confirm_label: string;
	can_run: boolean;
}

export interface ToolRunResult {
	status: 'done' | 'failed';
	success: boolean;
	message: string;
	activity: ToolActivity;
}
