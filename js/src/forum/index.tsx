import app from 'flarum/forum/app';
import { override } from 'flarum/common/extend';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import GamedayBoard from './components/GamedayBoard';
import registerWidgets from './widgets';

declare const m: any;

/**
 * The scoreboard becomes the game thread's hero.
 *
 * 🚨 The hero, not a post. A scoreboard written into the thread as a post would
 * be a post that rewrites itself every fifteen seconds — it would notify, it
 * would appear in search, it would be quotable, and once the thread got long it
 * would sit a hundred replies up from where anybody was reading. The hero is
 * the one part of the page that is about the discussion rather than in it, and
 * it stays put while the stream scrolls under it.
 *
 * 🚨 Appended to whatever the hero already was rather than replacing it. Themes
 * put things there — the title, a tag strip — and a scoreboard is an addition
 * to a game thread's heading, not a substitute for it.
 */
app.initializers.add('ernestdefoe-gameday', () => {
  /*
   * The scoreboard offered to Bespoke and Page Builder. Registration only —
   * nothing is drawn unless somebody places it, and neither host is required
   * to be present.
   */
  registerWidgets();

  /*
   * 🚨 `override`, not `extend`. An extend callback's return value is thrown
   * away — it is for reaching into a mutable ItemList, not for changing what a
   * method returns. Used here it ran, built the board, returned it, and
   * rendered nothing at all, with no error to say so.
   */
  override(DiscussionPage.prototype, 'hero', function (this: any, original: any) {
    const hero = original();
    const discussion = this.discussion;
    if (!discussion) return hero;

    let board = null;
    try { board = discussion.attribute('gamedayBoard'); } catch (e) { board = null; }
    if (!board) return hero;

    /*
     * 🚨 No `key` on the board. This return is a fragment, and Mithril refuses
     * a fragment where some children are keyed and others are not — the hero
     * beside it has no key, so keying the board threw on every render of every
     * game thread. It threw during the page's own view, which meant no error
     * reached the console the page came from and the thread simply sat on its
     * loading spinner: the same silent shape as calling a translator method
     * that does not exist.
     */
    /*
     * 🚨 Inside a `.container`, not loose in the hero.
     *
     * `Page-hero` is full-bleed, so the board ran the whole width of the window
     * while the posts under it sat in a column two hundred and ninety pixels
     * narrower — a scoreboard that lined up with nothing on the page.
     *
     * Core's own container class rather than a width written here: it is the
     * one thing on the page that already knows how wide this theme's content
     * is, and Bespoke moves it (1500px on fbsfb.com, 1140 by default). A number
     * copied into this stylesheet would be right on one site and wrong on the
     * next, and silently.
     */
    return [hero, m('.container', m(GamedayBoard, { discussion }))];
  });
});
