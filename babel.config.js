/**
 * Babel configuration for the Woodev Plugin Framework JS build.
 *
 * Extends @wordpress/babel-preset-default but forces the classic JSX runtime
 * to ensure WP 6.3+ compatibility.
 *
 * WHY CLASSIC RUNTIME:
 * The automatic JSX runtime emits `import { jsx } from 'react/jsx-runtime'`,
 * which DependencyExtractionWebpackPlugin maps to the WP script handle
 * `react-jsx-runtime`. WordPress core registers that handle only since WP 6.6.
 * This framework supports WP 6.3+, so the page would break on WP 6.3–6.5.
 *
 * With runtime:'classic', JSX compiles to createElement(…) / Fragment —
 * both sourced from `@wordpress/element` which DependencyExtractionWebpackPlugin
 * rewrites to wp.element at build time. The react-jsx-runtime handle is never
 * listed in index.asset.php; the only JS dependency is wp-element (WP 6.3+).
 *
 * JSX files MUST include:
 *   import { createElement, Fragment } from '@wordpress/element';
 * so that the pragma references are defined. See src/license-page/index.js for
 * the pattern; s6-p4 must apply it to every new JSX component file.
 *
 * IMPLEMENTATION NOTE — how the override works:
 * In Babel, plugins run before presets. The classic-runtime
 * @babel/plugin-transform-react-jsx placed here in `plugins` therefore runs
 * first and converts all JSX to createElement calls. The identical plugin
 * inside @wordpress/babel-preset-default then finds no remaining JSX and is a
 * no-op. The rest of the preset (preset-env, preset-typescript, plugin-runtime,
 * warning plugin) is unaffected.
 *
 * @see docs-internal/adr/007-react-admin-stack-wordpress-scripts.md
 */

module.exports = {
	presets: [
		require.resolve( '@wordpress/babel-preset-default' ),
	],
	plugins: [
		[
			require.resolve( '@babel/plugin-transform-react-jsx' ),
			{
				runtime: 'classic',
				pragma: 'createElement',
				pragmaFrag: 'Fragment',
			},
		],
	],
};
