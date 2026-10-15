// Woodev_Plugin::VERSION, read from the framework source at build time, so the site can never
// drift from the released version.
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Resolved from the working directory (website/), not import.meta.url: Astro bundles this module
// into dist/ for page rendering, where a module-relative path no longer points at the repo.
const SOURCE = resolve(process.cwd(), '../woodev/class-plugin.php');

function readFrameworkVersion() {
	const match = readFileSync(SOURCE, 'utf8').match(/const\s+VERSION\s*=\s*'([^']+)'/);
	if (!match) {
		throw new Error(`Framework version not found in ${SOURCE}`);
	}
	return match[1];
}

export const frameworkVersion = readFrameworkVersion();
