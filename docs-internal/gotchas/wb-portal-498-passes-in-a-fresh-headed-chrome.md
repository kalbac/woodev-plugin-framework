# Gotcha: [tooling/wb] — WB portal HTTP 498 is not a dead end: a fresh headed full Chrome passes it, headless and embedded browsers do not

> Tags: tooling/wb, research, anti-bot, playwright | Card: #1996 (M0b, 26.11.2026)

## What happens

`dev.wildberries.ru` HTML pages answer **HTTP 498** («Подозрительная активность. Пожалуйста, подождите») to
curl, to the `web_fetch` tool and to Orca's embedded browser — the research recorded this as «the portal
refused the automated fetch» and stopped. But the block is a **challenge bootstrap, not a ban**: a fresh
headed full Chrome with a temporary profile renders the page after the challenge clears itself. Both the
dev-portal sandbox spec page and `seller.wildberries.ru` instruction pages loaded on the first 5-second
poll. Waiting on the embedded browser or retrying curl never clears it.

A detector trap on top: matching the challenge by loose wording (`что-то не так`, `please wait`) false-positives
on the article's own prose — the seller marking article contains «а если с ним что-то не так». Match only the
challenge markers («Подозрительная активность», «Suspicious activity», «Новая попытка через»), or check for the
article text instead.

## Root cause

The portal fingerprints the client. Headless/embedded browsers and plain HTTP clients keep getting a fresh
challenge (the challenge id changes on every read); a real installed Chrome with a clean profile passes the
bootstrap (`create-token` 498 → 200) on its own. The numbered OpenAPI yamls
(`/api/swagger/yaml/en/NN-*.yaml`) are **not** behind the challenge — curl downloads them fine; only HTML
pages are. The `sandbox-environment` spec has **no yaml download at all**: it exists only embedded in the
page's Next.js payload (`self.__next_f.push` chunks → `const __redoc_state = {…spec:{data:{…}}}`), so the
page must be rendered to read it.

## Fix

❌ Wrong — curl / `web_fetch` / Orca embedded browser on a portal HTML page, then record «unreachable»:

```bash
curl https://dev.wildberries.ru/en/docs/openapi-other/sandbox-environment   # HTTP 498, forever
```

✅ Correct — Playwright with the installed Chrome, headed, a fresh temporary profile, and a patient poll
(the challenge clears itself; do not click it):

```js
const ctx = await chromium.launchPersistentContext(fs.mkdtempSync('/tmp/wb-'), {
  headless: false, channel: 'chrome',
});
// poll document.body.innerText every 5 s until the article text appears (both portals cleared
// within the first poll in this session); then read innerText / extract __redoc_state from the HTML
```

For the sandbox spec, extract `__redoc_state.spec.data` from the decoded `__next_f.push` chunks — it is the
full official OpenAPI JSON (snapshot committed in
[research/2026-11-26-wb-seller](../research/2026-11-26-wb-seller/sandbox-environment-2026-11-26.json)).
Close the context and delete the temporary profile afterwards; never attach the user's own browser profile.

## Related

- [WB Seller measurement log](../research/2026-11-26-wb-seller/measurement.md) — the pass that produced the fixtures, and the seller-portal timing
- [WB Seller API map](../research/2026-11-26-wb-seller/api-map.md) — §3 sandbox facts; the yaml checksums curl CAN fetch
- [Wildberries Seller 1.0 design](../specs/2026-11-26-wildberries-seller-plugin-design.md) — §3.2 M0b, §13.4 residual risks
