import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import whenVisible from '../whenVisible';

declare const m: any;

const EMOJI = ['🔥', '😱', '🙌', '😤', '💀', '🎉'];

/**
 * The roar: reactions that fly up the board while a game is being played.
 *
 * 🚨 Only while the thread is LIVE, and that is the whole point rather than a
 * limitation. A floating emoji says "this is happening now" — on a thread about
 * a game that finished on Saturday it says nothing, and it would be a second,
 * worse reaction button sitting beside the real one.
 */
export default class LiveReactions extends Component<{ discussion: any }> {
  seq = 0;
  since = 0;
  timer: any = null;
  cancelWait: (() => void) | null = null;
  sending = false;
  /** How many the server had to drop from the last poll. */
  overflow = 0;

  /**
   * Whether the board itself has scrolled out of view.
   *
   * 🚨 A live thread is mostly read at the BOTTOM — that is where the new posts
   * are — and the board is pinned at the top. Reactions that only fly inside
   * the board are reactions nobody sees during the half they are actually
   * reading. Once the board leaves the screen the roar detaches and follows the
   * reader down the page.
   */
  detached = false;
  observer: any = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.poll();
  }

  /** The viewport-wide sky, shared by every board on the page. */
  sky: HTMLElement | null = null;

  /**
   * 🚨 The sky lives on the BODY and is fixed to the viewport.
   *
   * Reactions rise over the whole page, not over the board. The board is pinned
   * at the top of a live thread and the reading happens at the bottom, so
   * anything confined to it is invisible for most of the time somebody is
   * actually watching the game.
   *
   * 🚨 On the body specifically. `position: fixed` is captured by any ancestor
   * carrying a transform, filter or backdrop-filter — the board has a gradient
   * stack today and could gain a transform tomorrow, at which point the sky
   * would silently start positioning against the board again.
   */
  ensureSky() {
    this.sky = document.querySelector('.GamedayReactions-sky--page');
    if (this.sky) return;

    const el = document.createElement('span');
    el.className = 'GamedayReactions-sky GamedayReactions-sky--page';
    el.setAttribute('aria-hidden', 'true');
    document.body.appendChild(el);
    this.sky = el;
  }

  oncreate(vnode: any) {
    super.oncreate(vnode);
    this.ensureSky();

    const board = vnode.dom?.closest('.GamedayBoard');
    if (!board || typeof IntersectionObserver === 'undefined') return;

    this.observer = new IntersectionObserver(
      (entries: any[]) => {
        const next = !entries[0].isIntersecting;
        if (next === this.detached) return;
        this.detached = next;
        m.redraw();
      },
      // A sliver still counts as on-screen: flipping the moment the last pixel
      // of the board leaves makes the roar jump about while somebody scrolls
      // slowly past it.
      { threshold: 0, rootMargin: '-40px 0px 0px 0px' }
    );

    this.observer.observe(board);
  }

  onremove() {
    if (this.timer) clearTimeout(this.timer);
    if (this.cancelWait) this.cancelWait();
    if (this.observer) this.observer.disconnect();
  }

  poll() {
    if (this.timer) clearTimeout(this.timer);

    app
      .request<any>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/gameday/reactions/${this.attrs.discussion.id()}`,
        params: { since: this.since },
      })
      .then((res: any) => {
        /*
         * 🚨 The server's clock, not ours. Asking "since my own last timestamp"
         * uses a clock that can be minutes off the server's, and the answer is
         * either the same reactions replayed forever or none at all.
         */
        this.since = res.now;
        this.overflow = res.truncated || 0;
        (res.reactions || []).forEach((r: any) => this.launch(r.e));
        m.redraw();
      })
      .catch(() => {
        /* a missed poll is a quiet moment, not a broken page */
      })
      .then(() => {
        // Three seconds: fast enough that a roar still feels like a roar,
        // slow enough that a full stand is not a request per second each.
        // Held while the tab is hidden: see whenVisible. Reactions only live
        // for a few seconds server-side, so nothing is lost by waiting.
        this.timer = setTimeout(() => {
          this.cancelWait = whenVisible(() => this.poll());
        }, 3000);
      });
  }

  /** Put one emoji in the air. */
  launch(emoji: string) {
    /*
     * 🚨 Appended straight to the body sky, NOT rendered through Mithril.
     *
     * The sky is outside this component's subtree, so a Mithril-managed child
     * would be torn out on the next redraw. Appending the node and removing it
     * on a timer is also how the XenForo build does it, and it keeps a hundred
     * floats off the redraw path entirely.
     */
    this.ensureSky();
    if (!this.sky) return;

    const float = document.createElement('span');
    float.className = 'GamedayReactions-float';
    float.textContent = emoji;

    // Across the whole viewport now, not the board's width.
    const left = 4 + Math.random() * 92;
    const drift = -90 + Math.random() * 180;
    // Further to travel than a board's height, so slower and more varied.
    const rise = 62 + Math.random() * 30;
    const dur = 4200 + Math.random() * 2600;
    const delay = Math.random() * 600;

    float.setAttribute(
      'style',
      `left:${left}%;--gd-drift:${drift}px;--gd-rise:${rise}vh;` + `animation-duration:${dur}ms;animation-delay:${delay}ms`
    );

    this.sky.appendChild(float);

    /*
     * 🚨 Removed on a timer rather than on the animation's own end event.
     * `animationend` never fires on a tab in the background, and a thread left
     * open on a second monitor would accumulate every emoji of the whole second
     * half and then try to render them all at once.
     */
    setTimeout(() => float.remove(), dur + delay + 400);
  }

  send(emoji: string) {
    if (this.sending) return;
    this.sending = true;

    // Fly it immediately for the person who pressed it: a button that waits
    // for a round trip before doing anything feels broken, and the poll will
    // not hand this one back to them anyway.
    this.launch(emoji);

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/gameday/reactions/${this.attrs.discussion.id()}`,
        body: { emoji },
      })
      .catch(() => {})
      .then(() => {
        this.sending = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className={`GamedayReactions${this.detached ? ' GamedayReactions--detached' : ''}`}>
        <div className="GamedayReactions-bar">
          {EMOJI.map((e) =>
            m(
              'button.GamedayReactions-btn',
              {
                type: 'button',
                key: e,
                onclick: () => this.send(e),
                // The emoji is the label; a screen reader needs the word.
                'aria-label': e,
              },
              e
            )
          )}
          {this.overflow > 0 ? <span className="GamedayReactions-more">+{this.overflow}</span> : null}
        </div>
      </div>
    );
  }
}
