import { extend } from 'flarum/common/extend';

declare const m: any;

/**
 * The home club's crest on the right of a game thread's hero, in place of the
 * tag chip above the title.
 *
 * 🚨 Only on a game thread. `items.remove('tags')` would otherwise strip the
 * tag strip from every discussion on the site — the hero component is shared,
 * and a thread that is not a game has nothing to put there instead.
 */
export default function addHeroCrest() {
  extend('flarum/forum/components/DiscussionHero', 'items', function (this: any, items: any) {
    const discussion = this.attrs?.discussion;

    let board: any = null;
    try {
      board = discussion?.attribute('gamedayBoard') || null;
    } catch (e) {
      board = null;
    }

    if (!board) return;

    const home = board.home || {};
    // The dark-ground mark: this hero is painted in the club's own colour.
    const logo = home.logo || home.logoLight;

    if (!logo) return;

    /*
     * 🚨 The chip goes only once there is a crest to replace it with.
     *
     * A fixture whose club has no mark on file would otherwise lose the one
     * thing on the hero that said which team the thread belongs to, and gain
     * nothing — the heading would read as a bare title on a coloured band.
     */
    items.remove('tags');

    items.add(
      'gamedayCrest',
      m('img.GamedayHero-crest', {
        src: logo,
        alt: home.name || '',
        title: home.name || '',
        loading: 'lazy',
      }),
      100
    );
  });
}
