---
title: Introduction
description: Learn what the Woodev Plugin Framework is, what it gives a WooCommerce plugin, and which release this documentation covers.
---

In this page you will learn what the Woodev Plugin Framework is for, which kinds of plugin you can build on it, and where to go next.

Covers framework release `%%FRAMEWORK_VERSION%%`.

## What the framework is

The Woodev Plugin Framework is a PHP library that you **bundle inside your plugin**. It is a single `woodev/` folder, shipped as a GitHub release. It is not a separate plugin that merchants install, and it has no runtime dependency on Composer.

The framework covers the parts that every WooCommerce plugin needs and that are easy to get subtly wrong:

- **Loading.** One call in your plugin's main file registers it. The framework checks the PHP, WordPress and WooCommerce versions and starts your plugin only when they are met.
- **A plugin base class.** Your main class extends it and receives ready-made subsystems: admin notices, install and upgrade routines, translation loading, cron and REST handlers, and logging.
- **Settings.** Every Woodev plugin contributes its settings to one shared page, «Woodev → Настройки» (Settings), so merchants always look in the same place.
- **WooCommerce compatibility.** HPOS (High-Performance Order Storage) and Cart/Checkout Blocks support is declared once, in the loader definition.
- **Licensing and updates.** The framework handles both for plugins sold on woodev.ru. Your plugin writes no licence or update code.
- **Shipping.** For carrier plugins: shipping methods and rates, packaging, pickup points, and a shipment orders page with tracking and documents.

## Plugin types and base classes

A plugin's type is decided by the class it extends. There is no flag or capability list to keep in sync.

| Plugin type | Extend | Notes |
|---|---|---|
| Pure WordPress | `Woodev_Plugin` | No WooCommerce code at all. Use `'platform' => 'wordpress'`. |
| WooCommerce | `\Woodev\Framework\Woocommerce_Plugin` | Adds the WooCommerce logger, HPOS and Blocks handling. |
| Shipping | `\Woodev\Framework\Shipping\Shipping_Plugin` | A `Woocommerce_Plugin` that also knows about carriers, rates and shipments. |
| Payment | `Woodev_Payment_Gateway_Plugin` | A `Woocommerce_Plugin` for payment gateways. Carried over from the v1 framework; see [Payment gateways](../../payments/payment-gateways/). |

You never `require` a framework file yourself. Framework classes are available as soon as the loader has started your plugin.

## Several plugins on one site

A merchant often runs several Woodev plugins, and each of them bundles its own copy of the framework. The framework handles this for you: **the newest bundled copy serves all of them.** Your plugin therefore runs against the newest framework on the site, not necessarily the one you bundled.

For that reason every plugin declares two versions in its loader definition: the framework version it bundles, and the oldest version it still works with. [Requirements & bundling](../requirements-and-bundling/) shows how to write code that is safe on both sides of that rule.

## How these docs are organised

| Section | Read it when you want to |
|---|---|
| **Getting started** (this section) | Set up a plugin, understand the loader definition and follow the naming conventions. |
| **Core** | Work with the plugin class, lifecycle and upgrades, HPOS and Blocks, external APIs, logging and helpers. |
| **Settings & admin** | Add settings tabs, connection tests, a setup wizard, admin notices and REST endpoints. |
| **Shipping** | Build a carrier integration. |
| **Payments** | Maintain a payment gateway plugin. |
| **Reference** | Look up hooks and filters. |

If you are new, read the Getting started pages in order:

1. [Requirements & bundling](../requirements-and-bundling/) — what you need and how to add the framework to a plugin.
2. [Your first plugin](../your-first-plugin/) — a complete, working WooCommerce plugin.
3. [Loader definition reference](../loader-definition-reference/) — every field of the registration array.
4. [Plugin layout & conventions](../plugin-layout-and-conventions/) — directory layout, naming and assets.

After that, [The plugin class](../../core/plugin-class/) is the natural next stop.
