import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';

declare const m: any;

interface Side { name: string; abbr: string; logo: string; score: number | null; hasBall: boolean }

interface Board {
  id: number;
  state: 'scheduled' | 'live' | 'final';
  periodLine: string;
  clock: string | null;
  possession: 'home' | 'away' | null;
  down: string | null;
  redZone: boolean;
  clockStale: boolean;
  kickoff: string | null;
  home: Side;
  away: Side;
}

/**
 * The scoreboard at the head of a game thread.
 *
 * 🚨 Dark in BOTH themes, deliberately. A scoreboard is a thing you look at in
 * a stadium, and one that turns white in light mode reads as a table of numbers
 * instead. It is also why the crests need no theme swap — the ground under them
 * never changes.
 */
export default class GamedayBoard extends Component<{ discussion: any }> {
  board: Board | null = null;
  timer: any = null;

  oninit(vnode: any) {
    super.oninit(vnode);

    // Straight off the discussion, so the board is there when the thread is
    // rather than arriving a beat later and shoving the first post down.
    try { this.board = this.attrs.discussion.attribute('gamedayBoard') || null; } catch (e) { this.board = null; }

    this.schedule();
  }

  onremove() {
    if (this.timer) clearTimeout(this.timer);
  }

  /**
   * How often to ask again.
   *
   * 🚨 Only while the game is being played. A finished game's board is finished
   * — polling it is a request per reader per minute that can only ever return
   * the same numbers. A scheduled one changes once, at kickoff, and the tick
   * job that opens the thread will have written it long before anybody notices.
   *
   * Fifteen seconds, not one: the state behind this is refreshed by a job that
   * runs every minute, so asking four times a minute is three requests that
   * cannot return anything new.
   */
  schedule() {
    if (this.timer) clearTimeout(this.timer);
    if (!this.board || this.board.state !== 'live') return;

    this.timer = setTimeout(() => this.refresh(), 15000);
  }

  refresh() {
    app.request<{ board: Board | null }>({
      method: 'GET',
      url: `${app.forum.attribute('apiUrl')}/gameday/board/${this.attrs.discussion.id()}`,
    })
      .then((res) => {
        if (res && res.board) this.board = res.board;
        m.redraw();
      })
      .catch(() => { /* a missed poll is a stale board, not a broken page */ })
      .then(() => this.schedule());
  }

  view() {
    const b = this.board;
    if (!b) return null;

    const t = (k: string, p?: any) => app.translator.trans(`ernestdefoe-gameday.forum.board_${k}`, p);

    return (
      <div className={`GamedayBoard GamedayBoard--${b.state}${b.redZone ? ' GamedayBoard--redzone' : ''}`}>
        <div className="GamedayBoard-strip">
          {this.side(b.away)}

          <div className="GamedayBoard-middle">
            <span className="GamedayBoard-period">{b.periodLine || t('scheduled')}</span>
            {b.clock ? <span className="GamedayBoard-clock">{b.clock}</span> : null}
            {/* Said out loud. A board that silently drops its clock looks
                broken in exactly the moment somebody is watching it. */}
            {b.clockStale ? <span className="GamedayBoard-stale">{t('stale')}</span> : null}
            {b.state === 'scheduled' && b.kickoff
              ? <span className="GamedayBoard-kickoff">{new Date(b.kickoff).toLocaleString(undefined, {
                  weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit',
                })}</span>
              : null}
          </div>

          {this.side(b.home)}
        </div>

        {b.down || b.redZone ? (
          <div className="GamedayBoard-situation">
            {b.down ? <span className="GamedayBoard-down">{b.down}</span> : null}
            {b.redZone ? <span className="GamedayBoard-redzone">{t('red_zone')}</span> : null}
          </div>
        ) : null}
      </div>
    );
  }

  side(s: Side) {
    return (
      <div className={`GamedayBoard-side${s.hasBall ? ' GamedayBoard-side--ball' : ''}`}>
        <span className="GamedayBoard-crest">
          {s.logo ? <img src={s.logo} alt="" aria-hidden="true" loading="lazy" referrerpolicy="no-referrer" /> : null}
        </span>
        <span className="GamedayBoard-team">
          {/* The abbreviation on a narrow screen, the name where there is room —
              one element, so the two never disagree about which is showing. */}
          <span className="GamedayBoard-name">{s.name}</span>
          <span className="GamedayBoard-abbr">{s.abbr || s.name}</span>
        </span>
        {/* 🚨 A marker with a label behind it, not a coloured dot. Possession is
            the one thing on this board somebody reads at a glance, and colour
            alone would put it out of reach of the readers who need the glance
            most. */}
        {s.hasBall
          ? <span
              className="GamedayBoard-ball"
              /* 🚨 extractText(trans(...)), never transText(). Flarum 2 has no
                 transText — the same missing-method family as transChoice, and
                 it fails the same way: it throws inside view(), Mithril stops,
                 and the page sits on a spinner with a correct API response
                 already in hand and nothing in the console. */
              title={extractText(app.translator.trans('ernestdefoe-gameday.forum.board_possession', { team: s.name }))}
            >●</span>
          : null}
        <span className="GamedayBoard-score">{s.score === null ? '–' : s.score}</span>
      </div>
    );
  }
}
