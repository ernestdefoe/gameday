import app from 'flarum/forum/app';
import ScoreboardWidget from './components/ScoreboardWidget';

declare const m: any;

/**
 * The scoreboard, offered to whichever page builders are installed.
 *
 * 🚨 Neither host is imported and neither is required. Game Day works with no
 * theme extension at all, and a hard import of Bespoke or Page Builder would
 * make this bundle fail to evaluate on a site that has neither — taking the
 * thread's own scoreboard down with it, which is the feature that does not
 * depend on either of them.
 */
export default function registerWidgets(): void {
  bespoke();
  pageBuilder();
}

/**
 * Bespoke.
 *
 * 🚨 Through the global queue rather than `app.bespoke.widgets.add(...)`.
 * Bespoke drains the queue on every lookup, so this works whether Bespoke's
 * initializer has run yet or not — and on a site without Bespoke it is an array
 * on `window` that nothing ever reads.
 */
function bespoke(): void {
  const queue = ((window as any).BespokeWidgetQueue = (window as any).BespokeWidgetQueue || []);

  queue.push({
    type: 'gameday-scoreboard',
    /*
     * 🚨 The FULL translation key, dots and all. Bespoke reads a dotted label
     * as a key in the registering extension's own locale and an undotted one as
     * a key in ITS locale — so 'widget_scoreboard' would be looked up under
     * Bespoke's namespace, miss, and fall back to printing the raw key in the
     * widget tray. The same rule governs every field label below.
     */
    label: 'ernestdefoe-gameday.forum.widget_scoreboard',
    icon: '🏈',
    zones: ['above-list', 'sidebar', 'below-list', 'footer', 'hero'],
    schema: [
      { key: 'title', type: 'text', label: 'ernestdefoe-gameday.forum.widget_field_title', default: 'Game Day' },
      { key: 'showLink', type: 'toggle', label: 'ernestdefoe-gameday.forum.widget_field_link', default: true },
      { key: 'hideWhenEmpty', type: 'toggle', label: 'ernestdefoe-gameday.forum.widget_field_hide', default: true },
    ],
    /*
     * 🚨 No `board` attr, which is what tells the component to fetch. Bespoke
     * widgets have no server half to resolve one — `undefined` here and a
     * server-resolved value in Page Builder is the entire difference between
     * the two hosts.
     */
    component: {
      view: (v: any) => m(ScoreboardWidget, { settings: v.attrs.settings || {} }),
    },
  });
}

/**
 * Page Builder.
 *
 * 🚨 Registered through the same order-proof queue Bespoke uses. Page Builder
 * looks a block's component up when it renders, so a component registered late
 * is still found — but `app.pageBuilder` itself does not exist until Page
 * Builder's own initializer has run, and which of two extensions initialises
 * first is not something either of them decides.
 */
function pageBuilder(): void {
  const component = {
    view: (v: any) =>
      m(ScoreboardWidget, {
        settings: v.attrs.settings || {},
        // Resolved server-side by ScoreboardBlock, so the board is drawn with
        // the page. `null` is a real answer here — "no game on" — and must not
        // be confused with Bespoke's `undefined`, which means "go and ask".
        board: (v.attrs.data || {}).board ?? null,
      }),
  };

  const registry = (app as any).pageBuilder;

  if (registry && typeof registry.registerBlock === 'function') {
    registry.registerBlock('gameday-scoreboard', component);
    return;
  }

  const queue = ((window as any).PageBuilderBlockQueue = (window as any).PageBuilderBlockQueue || []);

  queue.push({ type: 'gameday-scoreboard', component });
}
