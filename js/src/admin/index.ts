import app from 'flarum/admin/app';

/**
 * Admin entry.
 *
 * Everything is registered declaratively in ./extend — the Flarum 2 way — and
 * there is nothing imperative to do here. The file exists because the bundle
 * needs an entry point and because a future initializer belongs in it rather
 * than in the extender list.
 */
app.initializers.add('ernestdefoe/gameday', () => {});

export { default as extend } from './extend';
