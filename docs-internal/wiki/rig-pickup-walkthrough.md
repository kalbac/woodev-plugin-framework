> Вынесено из `CURRENT-STATE.md` в s87: это процедура, а не состояние, и она читалась на старте
> каждой сессии без нужды. `CURRENT-STATE.md` держит только указатель сюда.

# Риг-проход по слою ПВЗ — порядок, который реально работает (проверено s75)

Порядок важен, иначе кнопки ПВЗ просто нет:

1. `http://localhost:8973/?add-to-cart=12` — наполнить корзину, затем `/classic-checkout/`.
2. Выбрать метод доставки **Woodev Test Shipping** (при `free_shipping` кнопка ПВЗ скрыта).
3. Location-слой сидит на **`shipping_state` / `shipping_city` / `shipping_address_1`**.
   `billing_city` — select2 старого демо §8, НЕ наш слой, и его источник подсказок на риге ничего
   не отдаёт (это блокирует попытку оформить заказ целиком).
4. Локаль рига английская: DaData отдаёт транслит («Russia, Moscow city»), карьер — кириллицу.
   Не баг рига, а естественная почва для расхождения написаний.
5. Список пунктов открывается кнопкой `.woodev-pickup-list__toggle` (рядом
   `.woodev-pickup-filter__toggle` — это фильтр, легко перепутать).
6. Читать состояние сразу после подтверждения пункта **рано** — идёт `update_checkout`, поля в
   переходном виде. Подождать и перечитать.

## Related

- [../CURRENT-STATE.md](../CURRENT-STATE.md) — «Local rig», состояние и факты инфраструктуры
- [../gotchas/rig-checkout-url-is-the-block-checkout.md](../gotchas/rig-checkout-url-is-the-block-checkout.md) — почему `/checkout/` это не тот чекаут
- [../gotchas/rig-serves-the-working-tree-branch-switch-reverts-fixes.md](../gotchas/rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — риг обслуживает рабочее дерево
