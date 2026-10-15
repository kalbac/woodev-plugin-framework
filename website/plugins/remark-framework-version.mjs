// Replaces the %%FRAMEWORK_VERSION%% placeholder with Woodev_Plugin::VERSION.
import { visit } from 'unist-util-visit';
import { frameworkVersion } from './framework-version.mjs';

const PLACEHOLDER = '%%FRAMEWORK_VERSION%%';

export default function remarkFrameworkVersion() {
	const version = frameworkVersion;

	return (tree) => {
		visit(tree, (node) => {
			for (const key of ['value', 'url']) {
				if (typeof node[key] === 'string' && node[key].includes(PLACEHOLDER)) {
					node[key] = node[key].replaceAll(PLACEHOLDER, version);
				}
			}
		});
	};
}
