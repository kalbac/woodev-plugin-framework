# Playwright MCP does not fire WooCommerce's checkout submit; chrome-devtools MCP does

**Namespace:** `[rig/browser]` · **Discovered:** s44 (2026-07-30), classic-checkout e2e on the rig

## The trap

In s42 the whole §8 checkout field layer was browser-verified through **Playwright MCP** —
field takeover, region→city cascade, the A2 pickup gate, value survival across
`update_checkout` — everything worked. But clicking **«Оформить заказ»** produced nothing:
no `?wc-ajax=checkout` request, no order, no error. It was written off as "a test-harness
limitation, not a product defect" and the single most important assertion of the whole
feature — *that an order can actually be placed* — was left unverified and handed to the
operator as manual work.

That conclusion was right about the cause and wrong about the remedy: it is not "browser
automation cannot do this", it is **that particular MCP server**. Driving the exact same
page through the **chrome-devtools MCP** fires WooCommerce's checkout ajax normally:

```
POST /?wc-ajax=checkout  →  200  →  redirect to /order-received/16/
```

The order is created with all the field-layer meta intact. Nothing about the product had
to change.

## Why it matters beyond one click

The submit path is where a checkout feature actually earns its keep, and it is the one
place where a gate that *disables* `#place_order` could fight WooCommerce's own submit
handling. Skipping it leaves the highest-risk behaviour untested while every cheap
assertion around it is green — which reads, dangerously, like a verified feature.

The same run also showed the server-authority half is only reachable this way: re-enable
the button from the console, submit with an empty pickup point, and the server answers
`{"result":"failure"}` with both the conditional-required error and the independent
backstop error, creating no order. Neither the client gate nor a unit test can demonstrate
that.

## ❌ Wrong

> The click didn't fire WC's ajax. That's a harness limitation — the operator should
> verify the submit by hand.

Treating one automation tool's silence as a property of automation in general. It converts
a five-minute check into a blocking hand-off and leaves the riskiest path unverified.

## ✅ Correct

When a browser interaction silently does nothing under one MCP driver, **retry it under
the other one before concluding anything about the product or about "harness limits"**.
Both are available in this project:

- `mcp__plugin_playwright_playwright__*`
- `mcp__plugin_chrome-devtools-mcp_chrome-devtools__*`

For WooCommerce classic-checkout submits specifically, use **chrome-devtools MCP**. Useful
specifics from that run:

- `take_snapshot` yields the `uid`s; `fill_form` fills several fields in one call.
- select2/selectWoo fields need click → snapshot → click the option `uid` (the search
  input appears in the snapshot as its own combobox and accepts `fill`).
- Verify the outcome from `list_network_requests` + `get_network_request`, not from the
  page text — the ajax response body carries WooCommerce's real verdict.
- `take_screenshot` with `fullPage: true` can exceed the protocol timeout on a long
  checkout page; take a viewport shot instead.

## Related

- [[checkout-field-takeover-woocommerce-states]] — the redesign this run verified end to end
- [[wp-safe-remote-request-local-rig]] — other local rig traps
- [[wpenv-windows-gitbash-path-mangling]] — `MSYS_NO_PATHCONV=1` when driving wp-env/docker from Git Bash
