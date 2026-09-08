import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import TeamTagMapper from './components/TeamTagMapper';

declare const m: any;
const t = (k: string) => app.translator.trans('ernestdefoe-gameday.admin.settings.' + k);

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: 'ernestdefoe-gameday.enabled',
      type: 'boolean',
      label: t('enabled_label'),
      help: t('enabled_help'),
      default: false,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-gameday.author_id',
      type: 'number',
      label: t('author_label'),
      help: t('author_help'),
      default: 0,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-gameday.lead_minutes',
      type: 'number',
      label: t('lead_label'),
      help: t('lead_help'),
      default: 180,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-gameday.fallback_tag_id',
      type: 'number',
      label: t('fallback_tag_label'),
      help: t('fallback_tag_help'),
      default: 0,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-gameday.recaps',
      type: 'boolean',
      label: t('recaps_label'),
      help: t('recaps_help'),
      default: true,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-gameday.sticky_while_live',
      type: 'boolean',
      label: t('sticky_label'),
      /*
       * The help text says out loud when the setting cannot do anything. A
       * switch that is on and inert is worse than one that is missing: it tells
       * an operator the feature is working.
       */
      help: t('sticky_help') + (isEnabled('flarum-sticky') ? '' : ' ' + t('sticky_missing')),
      default: true,
    }))
    /*
     * 🚨 `customSetting`, not `setting`. `setting()`'s callback is invoked
     * expecting a descriptor object, so a vnode-returning one crashes the page;
     * `customSetting` passes the function through to the page's own builder.
     */
    .customSetting(() => m(TeamTagMapper), -10),
];

/**
 * Whether an extension is switched on.
 *
 * 🚨 Read from `extensions_enabled`, which is the list Flarum actually decides
 * from — `app.data.extensions` holds everything INSTALLED, enabled or not, so
 * checking that would call a disabled extension present and the help text would
 * be reassuring and wrong.
 */
function isEnabled(id: string): boolean {
  try {
    const raw = (app.data as any)?.settings?.extensions_enabled ?? '[]';

    return (JSON.parse(raw) as string[]).includes(id);
  } catch {
    // A malformed list is not worth an exception on a settings page; the help
    // text simply omits the warning.
    return true;
  }
}
