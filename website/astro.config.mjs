// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import { unified } from '@astrojs/markdown-remark';
import remarkFrameworkVersion from './plugins/remark-framework-version.mjs';

const repo = 'https://github.com/kalbac/woodev-plugin-framework';

export default defineConfig({
	site: 'https://kalbac.github.io',
	base: '/woodev-plugin-framework',
	markdown: {
		processor: unified({ remarkPlugins: [remarkFrameworkVersion] }),
	},
	integrations: [
		starlight({
			title: 'Woodev Plugin Framework',
			description:
				'A PHP framework for building WooCommerce plugins: loader, settings, API client, shipping and more.',
			social: [{ icon: 'github', label: 'GitHub', href: repo }],
			editLink: {
				baseUrl: `${repo}/edit/main/website/`,
			},
			lastUpdated: true,
			customCss: [
				'@fontsource-variable/inter',
				'@fontsource-variable/jetbrains-mono',
				'./src/styles/custom.css',
			],
			expressiveCode: {
				themes: ['github-dark', 'github-light'],
				useStarlightUiThemeColors: true,
				styleOverrides: {
					borderRadius: '0.5rem',
					codeFontFamily: 'var(--sl-font-system-mono)',
				},
			},
			sidebar: [
				{
					label: 'Getting started',
					items: [
						'getting-started/introduction',
						'getting-started/requirements-and-bundling',
						'getting-started/your-first-plugin',
						'getting-started/loader-definition-reference',
						'getting-started/plugin-layout-and-conventions',
					],
				},
				{
					label: 'Core',
					items: [
						'core/plugin-class',
						'core/lifecycle-and-upgrades',
						'core/migrating-from-v1',
						'core/dependencies-and-environment-checks',
						'core/hpos-and-blocks-compatibility',
						'core/calling-external-apis',
						'core/logging-and-debugging',
						'core/background-processing',
						'core/internationalisation',
						'core/helpers-reference',
					],
				},
				{
					label: 'Settings & admin',
					items: [
						'settings/overview',
						'settings/typed-settings',
						'settings/tabs-sections-and-cards',
						'settings/connection-tests',
						'settings/setup-wizard',
						'settings/admin-notices-and-messages',
						'settings/rest-endpoints',
						'settings/licensing',
					],
				},
				{
					label: 'Shipping',
					items: [
						'shipping/plugin-anatomy',
						'shipping/methods-and-rates',
						'shipping/method-features',
						'shipping/packaging-and-box-packing',
						'shipping/location-and-checkout-fields',
						'shipping/pickup-points-and-maps',
						'shipping/orders-export-documents-and-tracking',
					],
				},
				{
					label: 'Payments',
					items: [
						{
							slug: 'payments/payment-gateways',
							badge: { text: 'legacy', variant: 'caution' },
						},
					],
				},
				{
					label: 'Reference',
					items: ['reference/hooks-and-filters'],
				},
				'contributing',
			],
		}),
	],
});
