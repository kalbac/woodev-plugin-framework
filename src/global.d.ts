/**
 * Ambient module declarations for asset imports TypeScript has no types for on
 * its own — a side-effect `import './style.scss'` is valid webpack (via
 * `wp-scripts`' sass loader) but `tsc` has nothing to resolve it against
 * without this. Project-wide (not scoped to one entry) because every future
 * `.tsx` page entry importing its own stylesheet hits the identical gap.
 *
 * @package woodev-plugin-framework
 */

declare module '*.scss';
