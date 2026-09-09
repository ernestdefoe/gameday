// `export *` for the same reason as admin.ts: an extender added here later
// would otherwise be built and silently never registered.
export * from './src/forum/index';
