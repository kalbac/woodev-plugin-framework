/**
 * The chosen settlement key shared by the Blocks locality chooser and its address suggestions.
 *
 * @package woodev-plugin-framework
 */

let settlementKey: string | null = null;
const listeners = new Set< ( key: string | null ) => void >();

export function publishSettlementScope( key: string | null ): void {
	if ( key === settlementKey ) {
		return;
	}

	settlementKey = key;
	listeners.forEach( ( listener ) => listener( key ) );
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
	listeners.clear();
}
