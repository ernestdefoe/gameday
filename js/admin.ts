/*
 * 🚨 `export *`, not a bare `import`.
 *
 * Flarum reads the `extend` array off the bundle's EXPORTS. A bare import runs
 * the module — registering the initializer, which is why the extension looked
 * alive — and exports nothing, so every setting declared in ./src/admin/extend
 * was built, translated, and never once shown. The admin page rendered with no
 * settings on it at all and no error anywhere.
 */
export * from './src/admin/index';
