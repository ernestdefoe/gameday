import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: any;

interface Side {
  name: string;
  abbr: string;
  logo: string;
  score: number | null;
  hasBall: boolean;
}

interface Link {
  id: number;
  slug: string;
  title: string;
  commentCount: number;
}

export interface WidgetBoard {
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
  discussion: Link | null;
}

interface Attrs {
  settings?: Record<string, any>;
  /**
   * A board the host already has.
   *
   * 🚨 Page Builder resolves this server-side and hands it over, so the block
   * is drawn with the page. Bespoke's widget tray has no server half, so it
   * arrives without one and fetches. The two paths differ ONLY here — the poll,
   * the markup and the states below are shared, which is what stops the same
   * scoreboard drifting into two scoreboards.
   */
  board?: WidgetBoard | null;
}

/**
 * The scoreboard as a widget: whatever game is on, wherever it is placed.
 *
 * 🚨 Not the same question as the board at the head of a game thread. That one
 * is about the thread it sits on; this one is about the site — live first, then
 * the next kickoff, then the last final for a few hours so the panel is not
 * blank for six days of the week.
 *
 * 🚨 Dark in both themes, like the thread's board and for the same reason: a
 * scoreboard is a thing you look at in a stadium, and one that turns white in
 * light mode reads as a table of numbers.
 */
export default class ScoreboardWidget extends Component<Attrs> {
  board: WidgetBoard | null = null;
  loading = false;
  timer: any = null;

  oninit(vnode: Mithril.Vnode<Attrs>) {
    super.oninit(vnode);

    this.board = this.attrs.board ?? null;

    if (this.attrs.board === undefined) {
      this.loading = true;
      this.refresh();
    } else {
      this.schedule();
    }
  }

  onremove() {
    if (this.timer) clearTimeout(this.timer);
  }

  /**
   * How often to ask again.
   *
   * 🚨 Every fifteen seconds while a game is being PLAYED, and almost never
   * otherwise. A widget can sit on a home page every visitor loads, so a poll
   * that ran regardless would be a request per reader per interval, all year,
   * to be told the same thing.
   *
   * 🚨 A scheduled game still needs one — otherwise the widget shows "Kickoff
   * 7:30pm" for the whole first quarter to anybody who left the tab open. Once
   * a minute, and only inside the half hour before kickoff, which is the only
   * window in which the answer can change.
   */
  schedule() {
    if (this.timer) clearTimeout(this.timer);
    if (!this.board) return;

    if (this.board.state === 'live') {
      this.timer = setTimeout(() => this.refresh(), 15000);
      return;
    }

    if (this.board.state === 'scheduled' && this.nearKickoff()) {
      this.timer = setTimeout(() => this.refresh(), 60000);
    }
  }

  nearKickoff(): boolean {
    if (!this.board?.kickoff) return false;

    const away = new Date(this.board.kickoff).getTime() - Date.now();

    // Already past kickoff counts: the feed can be a minute or two behind the
    // whistle, and that is exactly when somebody is watching this.
    return away < 1800000;
  }

  refresh() {
    app
      .request<{ board: WidgetBoard | null }>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/gameday/board`,
      })
      .then((res) => {
        this.board = (res && res.board) || null;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        // A missed poll is a stale board, not a broken page. The FIRST fetch
        // failing is different — it leaves nothing to draw, and the empty state
        // below is what says so.
        this.loading = false;
        m.redraw();
      })
      .then(() => this.schedule());
  }

  view() {
    const s = this.attrs.settings || {};
    const b = this.board;
    const t = (k: string, p?: any) => app.translator.trans(`ernestdefoe-gameday.forum.widget_${k}`, p);

    if (!b) {
      // 🚨 Nothing at all, by default. Off-season this panel would otherwise
      // say "no games" every day for months, which is a worse answer than the
      // space it takes up.
      if (this.loading || s.hideWhenEmpty !== false) return null;

      return (
        <div className="GamedayWidget GamedayWidget--empty">
          {s.title ? <h4 className="GamedayWidget-title">{s.title}</h4> : null}
          <p className="GamedayWidget-none">{t('no_game')}</p>
        </div>
      );
    }

    const link = s.showLink === false ? null : b.discussion;

    return (
      <div className={`GamedayWidget GamedayWidget--${b.state}${b.redZone ? ' GamedayWidget--redzone' : ''}`}>
        {s.title ? <h4 className="GamedayWidget-title">{s.title}</h4> : null}

        <div className="GamedayWidget-board" aria-live="polite">
          <div className="GamedayWidget-status">
            {b.state === 'live' ? (
              <span className="GamedayWidget-live">
                <span className="GamedayWidget-pip" aria-hidden="true" />
                {t('live')}
              </span>
            ) : null}
            <span className="GamedayWidget-period">{this.statusLine(b, t)}</span>
          </div>

          {this.side(b.away)}
          {this.side(b.home)}

          {b.down || b.redZone ? (
            <div className="GamedayWidget-situation">
              {b.down ? <span className="GamedayWidget-down">{b.down}</span> : null}
              {b.redZone ? <span className="GamedayWidget-rz">{t('red_zone')}</span> : null}
            </div>
          ) : null}
        </div>

        {/* 🚨 The link is absent, not disabled, when the reader cannot open the
            thread — the server never sent one. A greyed-out link would disclose
            that the thread exists, which is the thing being withheld. */}
        {link ? (
          <a className="GamedayWidget-link" href={app.route('discussion', { id: `${link.id}-${link.slug}` })}>
            <span className="GamedayWidget-linkLabel">{t('to_the_thread')}</span>
            {link.commentCount > 1 ? (
              <span className="GamedayWidget-count">{link.commentCount}</span>
            ) : null}
          </a>
        ) : null}
      </div>
    );
  }

  statusLine(b: WidgetBoard, t: (k: string, p?: any) => any) {
    if (b.state === 'scheduled') {
      return b.kickoff
        ? new Date(b.kickoff).toLocaleString(undefined, {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            hour: 'numeric',
            minute: '2-digit',
          })
        : t('scheduled');
    }

    // The clock beside the period where there is one worth printing — the shape
    // has already dropped it if it is stale or sitting at a period boundary.
    if (b.state === 'live') {
      if (b.clockStale) return b.periodLine ? `${b.periodLine} · ${extractText(t('stale'))}` : extractText(t('stale'));

      return b.clock ? `${b.periodLine} · ${b.clock}` : b.periodLine;
    }

    return b.periodLine;
  }

  side(s: Side) {
    return (
      <div className={`GamedayWidget-side${s.hasBall ? ' GamedayWidget-side--ball' : ''}`}>
        <span className="GamedayWidget-crest">
          {s.logo ? <img src={s.logo} alt="" aria-hidden="true" loading="lazy" referrerpolicy="no-referrer" /> : null}
        </span>

        {/* 🚨 The abbreviation only where it says something the name did not.
            Plenty of teams ARE their abbreviation — TCU, UCLA, SMU — and a
            widget this narrow shows one of the two, never both. */}
        <span className="GamedayWidget-team">{s.abbr || s.name}</span>

        {/* 🚨 A marker with a label behind it, never colour alone. Possession is
            the one thing here read at a glance, and a glance is exactly what a
            colour-only cue takes away from the readers who most rely on it. */}
        {s.hasBall ? (
          <span
            className="GamedayWidget-ball"
            title={extractText(app.translator.trans('ernestdefoe-gameday.forum.board_possession', { team: s.name }))}
          >
            ●
          </span>
        ) : null}

        <span className="GamedayWidget-score">{s.score === null ? '–' : s.score}</span>
      </div>
    );
  }
}
