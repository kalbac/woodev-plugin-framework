# A cached asset under an unchanged `?ver=` reads as a feature that does not work

**Namespace:** `[rig/browser]`
**Found:** s90 (25.08.2026).

## The trap

Two rig passes in a row read as *"the client ignores the server's response"*: `/select` answered
`cancelled: true` with the message, the network panel showed it, and on screen absolutely nothing
happened — no cleared field, no notice. The obvious conclusion was a client-side defect, and it was
one keystroke from being written into a card.

It was a cached copy of `location-cascade.js`.

Frontend assets in this layer are enqueued directly and versioned by the file's own `filemtime()`
(`enqueue_style_if_built()` and its script twin). The URL is therefore stable while the file is:

```
/wp-content/plugins/woodev-test-plugin/woodev/shipping-method/assets/js/frontend/location-cascade.js?ver=1787583976
```

**A hard reload was not enough**, and neither was a fresh isolated browser context. What finally
made the difference was instrumenting the file — which changed its mtime, which changed `?ver=`,
which made the browser fetch it.

Worse, the check that *should* have caught it did not: fetching the same URL from the page and
grepping the response for the new function reported the new code, because that fetch was served from
the same cache entry.

## Why it bites here specifically

A git checkout or branch switch rewrites the file and moves its mtime, so `?ver=` normally changes on
its own. The window is the case where the browser already holds a response for the URL it is about
to request again — a page opened before the switch, a context reused across scenarios, a `fetch()`
made from the page itself.

## ✅ What to do

- **On the rig, keep DevTools open with "Disable cache" ticked** for any pass that is judging
  whether a code change took effect. Everything else is a coin flip.
- Treat "the feature does nothing at all, and the server side is provably correct" as a **caching
  hypothesis first**, before a client-side defect. Confirm by comparing the `?ver=` in the page
  against the file's real mtime:

  ```bash
  stat -c %Y woodev/shipping-method/assets/js/frontend/location-cascade.js
  ```

- Do not trust an in-page `fetch()` of the same URL as proof of what is executing — add a cache
  buster or read the mtime instead.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — the other way the rig serves something other than what you think
- [wp-scripts-css-enqueue-version-by-mtime](wp-scripts-css-enqueue-version-by-mtime.md) — where the `?ver=` comes from
