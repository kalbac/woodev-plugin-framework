# Gotcha: [rig/browser] — select the checkout city from suggestions
> Tags: rig, checkout, location, smoke test | Session: s178

On the classic checkout, typing a city name directly leaves the default locality record in place. A Pushkin smoke can then report no carrier rates even though the typed address looks correct; the buyer has not actually selected the settlement record.

❌ **Wrong:** type “Pushkin” into the city field and treat the displayed text as proof the checkout uses Pushkin.

✅ **Correct:** choose the settlement from the framework's location suggestions, as a buyer does, then verify the selected locality and rates. The s178 review-build smoke confirmed Pushkin through this buyer path.

## Related

- [the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field.md](the-pickup-modal-s-locality-comes-from-the-resolved-record-not-the-city-field.md) — locality comes from the resolved record
- [../gotcha-index/rig.md](../gotcha-index/rig.md) — rig index
