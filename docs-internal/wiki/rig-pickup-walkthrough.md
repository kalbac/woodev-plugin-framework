# Rig pickup walkthrough — classic checkout

> Classic-checkout procedure, originally measured in s75; the block adapter has since shipped.
> Check the served branch, provider and checkout address state before treating these as live values.

1. Add an item: `http://localhost:8973/?add-to-cart=12`, then open `/classic-checkout/`.
2. Select **Woodev Test Shipping**; the pickup container is hidden for `free_shipping`.
3. The shipping column uses `shipping_state`, `shipping_city`, `shipping_address_1` when the
   separate-shipping checkbox is selected. Rule 7b also fans values into `billing_*`; Rule 7c
   chooses the active column from the live checkbox. Inspect the current form state.
4. The site locale is `en_US`; the admin user is `ru_RU` (s170). DaData can return transliteration
   ("Russia, Moscow city") while a carrier returns Cyrillic, so compare identity as well as labels.
5. Open the picker with visible `button.woodev-pickup-trigger`. The button can exist while its
   container is hidden; test visibility. The modal is `.woodev-modal__content` (`role=dialog`),
   with `.woodev-modal__title` and `.woodev-modal__close`. The old
   `.woodev-pickup-list__toggle` selector never existed.
6. After confirming a point, wait for `update_checkout` to settle before reading the fields again.

## Automated coverage

`npm run test:e2e` (`tests/e2e/checkout-pickup.spec.js`, #723) covers the classic pickup sequence,
field policies, modal and order-button gate. It does not replace operator acceptance.
The old missing-takeover-field blocker is resolved by server #708 and client #721.

## Related

- [../CURRENT-STATE.md](../CURRENT-STATE.md) — live state and infrastructure
- [../gotchas/rig-checkout-url-is-the-block-checkout.md](../gotchas/rig-checkout-url-is-the-block-checkout.md) — classic and block probe selectors
- [../gotchas/rig-serves-the-working-tree-branch-switch-reverts-fixes.md](../gotchas/rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — the rig serves the working tree
