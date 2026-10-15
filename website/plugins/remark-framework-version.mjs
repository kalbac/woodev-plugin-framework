// Replaces the %%FRAMEWORK_VERSION%% placeholder with Woodev_Plugin::VERSION, read from the
// framework source at build time, so a page can never drift from the released version.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { visit } from 'unist-util-visit';

const PLACEHOLDER = '%%FRAMEWORK_VERSION%%';
const SOURCE = fileURLToPath(new URL('../../woodev/class-plugin.php', import.meta.url));

function readFrameworkVersion() {
	const match = readFileSync(SOURCE, 'utf8').match(/const\s+VERSION\s*=\s*'([^']+)'/);
	if (!match) {
		throw new Error(`Framework version not found in ${SOURCE}`);
	}
	return match[1];
}

export default function remarkFrameworkVersion() {
	const version = readFrameworkVersion();

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
