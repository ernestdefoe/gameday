import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';

declare const m: any;

const EMOJI = ['🔥', '😱', '🙌', '😤', '💀', '🎉'];

interface Float { id: number; emoji: string; left: number; drift: number; dur: number; delay: number }

/**
 * The roar: reactions that fly up the board while a game is being played.
 *
 * 🚨 Only while the thread is LIVE, and that is the whole point rather than a
 * limitation. A floating emoji says "this is happening now" — on a thread about
 * a game that finished on Saturday it says nothing, and it would be a second,
 * worse reaction button sitting beside the real one.
 */
export default class LiveReactions extends Component<{ discussion: any }> {
  floats: Float[] = [];
  seq = 0;
  since = 0;
  timer: any = null;
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

  oncreate(vnode: any) {
    super.oncreate(vnode);

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
    if (this.observer) this.observer.disconnect();
  }

  poll() {
    if (this.timer) clearTimeout(this.timer);

    app.request<any>({
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
      .catch(() => { /* a missed poll is a quiet moment, not a broken page */ })
      .then(() => {
        // Three seconds: fast enough that a roar still feels like a roar,
        // slow enough that a full stand is not a request per second each.
        this.timer = setTimeout(() => this.poll(), 3000);
      });
  }

  /** Put one emoji in the air. */
  launch(emoji: string) {
    const id = ++this.seq;

    this.floats.push({
      id,
      emoji,
      // Spread across the board's width, avoiding the very edges.
      left: 6 + Math.random() * 88,
      // A sideways wander, so a hundred of them do not rise in columns.
      drift: -40 + Math.random() * 80,
      dur: 2600 + Math.random() * 1400,
      delay: Math.random() * 500,
    });

    /*
     * 🚨 Removed on a timer rather than left for the animation's own end
     * event. `animationend` never fires on a tab in the background, and a
     * thread left open on a second monitor would accumulate every emoji of
     * the whole second half and then try to render them all at once.
     */
    const life = 4600;
    setTimeout(() => {
      this.floats = this.floats.filter((f) => f.id !== id);
      m.redraw();
    }, life);
  }

  send(emoji: string) {
    if (this.sending) return;
    this.sending = true;

    // Fly it immediately for the person who pressed it: a button that waits
    // for a round trip before doing anything feels broken, and the poll will
    // not hand this one back to them anyway.
    this.launch(emoji);

    app.request({
      method: 'POST',
      url: `${app.forum.attribute('apiUrl')}/gameday/reactions/${this.attrs.discussion.id()}`,
      body: { emoji },
    })
      .catch(() => {})
      .then(() => { this.sending = false; m.redraw(); });
  }

  view() {
    return (
      <div className={`GamedayReactions${this.detached ? ' GamedayReactions--detached' : ''}`}>
        <div className="GamedayReactions-sky" aria-hidden="true">
          {this.floats.map((f) =>
            m('span.GamedayReactions-float', {
              key: f.id,
              style: `left:${f.left}%;--gd-drift:${f.drift}px;animation-duration:${f.dur}ms;animation-delay:${f.delay}ms`,
            }, f.emoji)
          )}
        </div>

        <div className="GamedayReactions-bar">
          {EMOJI.map((e) =>
            m('button.GamedayReactions-btn', {
              type: 'button',
              key: e,
              onclick: () => this.send(e),
              // The emoji is the label; a screen reader needs the word.
              'aria-label': e,
            }, e)
          )}
          {this.overflow > 0
            ? <span className="GamedayReactions-more">+{this.overflow}</span>
            : null}
        </div>
      </div>
    );
  }
}
