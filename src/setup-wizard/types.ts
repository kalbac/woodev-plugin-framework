/**
 * Setup Wizard — types a plugin author needs to write a custom step component (D4).
 *
 * A plugin registers a script (`wp_register_script()`), declares the step with
 * `Step::set_component( $handle, 'ExportName' )`, and publishes the component from that script:
 *
 *     window.woodevSetupWizardComponents = window.woodevSetupWizardComponents || {};
 *     window.woodevSetupWizardComponents[ 'my-plugin-wizard' ] = { ExportName: MyStep };
 *
 * The framework renders `MyStep` inside the standard step frame (title, description, error
 * banner, Back / Skip / Continue) and passes it {@link SetupWizardStepComponentProps}.
 *
 * @package woodev-plugin-framework
 */

/** One field of a settings step, as the PHP bootstrap describes it (the Settings API schema slice). */
export interface SetupWizardField {
	type: string;
	name?: string;
	value?: unknown;
	controlType?: string | null;
	options?: Record<string, string>;
	required?: boolean;
	[ key: string ]: unknown;
}

/** An action bound to a step (`Step::add_action()`). */
export interface SetupWizardStepAction {
	id: string;
	label: string;
	/** The framework asks the merchant to confirm before it runs. */
	destructive: boolean;
	/** Confirmation text ('' = the framework's generic one). */
	confirm: string;
}

/** The custom component a step declares (`Step::set_component()`). */
export interface SetupWizardComponentRef {
	/** Registered script handle. */
	handle: string;
	/** Name under which the script published the component. */
	export: string;
}

/** One step of the graph, as the server describes it. */
export interface SetupWizardStep {
	id: string;
	/** False for a step the server-side predicate hides now (then only `id` is present). */
	visible?: boolean;
	label: string;
	type: 'settings' | 'content' | 'finish';
	description: string;
	fields: Record<string, SetupWizardField>;
	/** Trusted HTML of a content step. */
	content: string;
	skippable: boolean;
	/** The step has a server-side validation callback. */
	validates: boolean;
	component: SetupWizardComponentRef | null;
	actions: SetupWizardStepAction[];
}

/** What a step action answers. `cancelled` = the merchant declined the confirmation. */
export interface SetupWizardActionAnswer {
	status: 'success' | 'error' | 'cancelled';
	message: string;
	data: Record<string, unknown>;
}

/** The props the framework passes to a custom step component. */
export interface SetupWizardStepComponentProps {
	/** The step's descriptor (label, fields with their saved values, actions…). */
	step: SetupWizardStep;
	/**
	 * What the merchant changed on this step so far — field id => value. Only edited fields
	 * are here (and only they are saved); an untouched field keeps its saved value.
	 */
	values: Record<string, unknown>;
	/** Replace the step's edits. Pass the whole map: `onChange( { ...values, api_key: 'x' } )`. */
	onChange: ( values: Record<string, unknown> ) => void;
	/** Per-field errors from the last refused save, field id => message. */
	errors: Record<string, string>;
	/** Whether to reveal client-side validation errors on the fields. */
	showErrors: boolean;
	/** A save or a Continue is in flight. */
	busy: boolean;
	/**
	 * Validate and persist the edits on the server WITHOUT leaving the step (the same contract
	 * as Continue: validation callback → settings → on_save). Resolves `true` when the server
	 * accepted it; on `false` the framework has already shown the errors.
	 */
	save: () => Promise<boolean>;
	/** The standard Continue: save (when the step needs it), then move to the next visible step. */
	next: () => Promise<boolean>;
	/** Go to the previous step. */
	back: () => void;
	/** Skip the step without saving (only when `step.skippable`). */
	skip: () => void;
	/**
	 * Run one of the step's actions on the server. A destructive action first shows the
	 * framework's confirmation panel; if the merchant declines, this resolves
	 * `{ status: 'cancelled' }`. The edits travel with the request; nothing is saved by it.
	 */
	runAction: ( actionId: string ) => Promise<SetupWizardActionAnswer>;
}

/** A step component: any React component taking the props above. */
export type SetupWizardStepComponent = ( props: SetupWizardStepComponentProps ) => unknown;

declare global {
	interface Window {
		/** `{ [ script handle ]: { [ export name ]: component } }`, filled by the plugins' scripts. */
		woodevSetupWizardComponents?: Record<string, Record<string, unknown>>;
	}
}
