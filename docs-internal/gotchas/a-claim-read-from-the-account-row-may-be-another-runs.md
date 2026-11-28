# Gotcha: [framework/contracts] — a claim token read from the account row may be another run's
> Tags: contracts, marketplace, jobs, concurrency | Session: card #2054 (28.11.2026)

## What happens

A marketplace export gateway proves the account claim in the middle of its own work: it reads the claim token from the account
row at the first card and renews that token before each write. A run that paused for longer than the claim lasts (120 s) reads
the token of the run that took the account over, renews it successfully and goes on sending beside that run.

## Root cause

The account row holds the claim of whoever holds the account **now**, not the claim of the run that is asking. Between the job's
claim and the gateway's first call the claim may expire and be taken. Only the job knows which token is this run's.

The same trap has a second half: the exception that stops such a run, `Jobs\Claim_Lost`, is not a marketplace answer. A catch-all
in the adapter that wraps it, or an `Error_Classifier` that answers for any `Throwable`, turns a clean stop into a failed task
and a retry written under a claim the run no longer has.

## Fix

```php
// ❌ the row may already hold another run's token
public function build_item( ... ): array {
	$this->token ??= $this->read_claim_token( $account );
}

// ✅ the job hands over this run's claim before anything else (Flows\Receives_Export_Claim)
public function receive_export_claim( Account $account, callable $hold ): void {
	$this->hold = $hold; // call_user_func( $this->hold ) between remote calls and before each write
}
```

Let `Claim_Lost` through unwrapped. `Export_Batch_Runner` rethrows it from `submit()` and from the card list read before the
classifier is asked, so the classifier needs no branch for it there.

## Related

- [a-method-added-to-an-adapter-interface-fatals-released-plugins](a-method-added-to-an-adapter-interface-fatals-released-plugins.md) — why the hand-over is a separate optional interface
- [Marketplace module](../wiki/marketplace-admin.md#the-runs-claim-handed-to-the-export-gateway-2054-2129)
