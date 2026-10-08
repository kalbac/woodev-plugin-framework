/**
 * The chosen settlement key shared by the Blocks locality chooser and its address suggestions.
 *
 * @package woodev-plugin-framework
 */

let settlementKey: string | null = null;
let initialized = false;
const listeners = new Set< ( key: string | null ) => void >();

export function publishSettlementScope( key: string | null ): void {
	initialized = true;
	settlementKey = key;
	listeners.forEach( ( listener ) => listener( key ) );
}

export function initializeSettlementScope( key: string | null ): void {
	if ( ! initialized ) {
		settlementKey = key;
		initialized = true;
	}
}

export function readSettlementScope(): string | null {
	return settlementKey;
}

export function subscribeSettlementScope( listener: ( key: string | null ) => void ): () => void {
	listeners.add( listener );
	return () => listeners.delete( listener );
}

export function resetSettlementScope(): void {
	settlementKey = null;
	initialized = false;
	listeners.clear();
}
