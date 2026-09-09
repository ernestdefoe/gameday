import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import {
  attach,
  currentBoards,
  detach,
  isLoading,
  seed,
  type Side,
  type WidgetBoard,
} from '../boardStore';

declare const m: any;

export type { WidgetBoard };

interface Attrs {
  settings?: Record<string, any>;
  /**
   * Games the host already resolved server-side (Page Builder), so the block is
   * drawn with the page rather than a request later.
   *
   * 🚨 Handed to the store, not held here. This component is re-created from
   * scratch on every redraw — see boardStore — so anything it kept for itself
   * would be thrown away and asked for again a few milliseconds later.
   */
  boards?: WidgetBoard[];
}

/**
 * The scoreboard as a widget: whatever games are on, wherever it is placed.
 *
 * 🚨 Not the same question as the board at the head of a game thread. That one
 * is about the thread it sits on; this one is about the site — live first, then
 * the next kickoffs, then recent finals so the panel is not blank for the six
 * days of the week nobody is playing.
 *
 * 🚨 A READER, holding no state of its own. Every field it might have kept
 * lives in boardStore, because Bespoke rebuilds its zone hosts on every redraw
 * and this component does not survive one.
 */
export default class ScoreboardWidget extends Component<Attrs> {
  oninit(vnode: Mithril.Vnode<Attrs>) {
    super.oninit(vnode);

    if (this.attrs.boards !== undefined) {
      seed(this.attrs.boards);
    }

    attach();
  }

  onremove() {
    detach();
  }

  view() {
    const s = this.attrs.settings || {};
    const t = (k: string, p?: any) => app.translator.trans(`ernestdefoe-gameday.forum.widget_${k}`, p);

    const boards = currentBoards();

    if (boards.length === 0) {
      // 🚨 Nothing at all, by default. Off-season this panel would otherwise
      // say "no games" every day for months, which is a worse answer than the
      // space it takes up.
      if (isLoading() || s.hideWhenEmpty !== false) return null;

      return (
        <div className="GamedayWidget GamedayWidget--empty">
          {s.title ? <h4 className="GamedayWidget-title">{s.title}</h4> : null}
          <p className="GamedayWidget-none">{t('no_game')}</p>
        </div>
      );
    }

    /*
     * 🚨 Every game is rendered, and the CONTAINER decides how many are seen.
     *
     * Where a widget goes is the operator's choice — a sidebar, a full-width
     * row, a hero — and the component is not told which. A container query is,
     * so the stylesheet shows one stacked card in a narrow column and a
     * scrolling strip of them given the width of a page. Measuring the element
     * in JavaScript would be the same answer arrived at a frame later, after a
     * layout the reader can see.
     *
     * It also means the strip is real markup rather than a JS-built list: it
     * scrolls, it keyboard-scrolls, and it is all there for a reader whose
     * browser never runs the query.
     */
    return (
      <div className="GamedayWidget">
        {s.title ? <h4 className="GamedayWidget-title">{s.title}</h4> : null}

        <div
          className="GamedayWidget-strip"
          aria-live="polite"
          // A named role only where it is actually a scrolling region, which
          // is what the class does; the label says what is scrolling.
          aria-label={extractText(t('scoreboard'))}
        >
          {boards.map((b) => this.game(b, s, t))}
        </div>
      </div>
    );
  }

  game(b: WidgetBoard, s: Record<string, any>, t: (k: string, p?: any) => any) {
    const link = s.showLink === false ? null : b.discussion;

    return (
      <div
        key={b.id}
        className={`GamedayWidget-game GamedayWidget-game--${b.state}${b.redZone ? ' GamedayWidget-game--redzone' : ''}`}
      >
        <div className="GamedayWidget-board">
          <div className="GamedayWidget-status">
            {b.state === 'live' ? (
              <span className="GamedayWidget-live">
                <span className="GamedayWidget-pip" aria-hidden="true" />
                {t('live')}
              </span>
            ) : null}
            <span className="GamedayWidget-period">{this.statusLine(b, t)}</span>
          </div>

          {/*
            🚨 Its own row, not appended to the period line. "Waiting for the
            feed" beside "3rd Quarter" overflows a sidebar and is cut to
            "3RD QUARTER · WAITI…", which reads as a rendering fault rather
            than as the honest statement it is — and it appears precisely when
            somebody is staring at the board wondering why it stopped.
          */}
          {b.clockStale ? <div className="GamedayWidget-stale">{t('stale')}</div> : null}

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
    // The clock beside the period only where there is one worth printing —
    // shape() has already dropped it if it is stale or at a period boundary,
    // and the staleness is said on its own row below.
    if (b.state === 'live') {
      return b.clock ? `${b.periodLine} · ${b.clock}` : b.periodLine;
    }

    return b.periodLine;
  }

  side(s: Side) {
    return (
      <div className={`GamedayWidget-side${s.hasBall ? ' GamedayWidget-side--ball' : ''}`}>
        {/*
          🚨 Both crests in the markup, one shown by CSS.
          A widget follows the page theme, and a mark drawn for a dark ground
          is white-on-transparent for plenty of teams — invisible on the light
          panel, and an empty box reads as a broken image rather than as a
          styling choice. Swapping the `src` in JavaScript would work too and
          would do it a frame after the theme changed, in front of the reader.
        */}
        <span className="GamedayWidget-crest">
          {s.logoLight ? (
            <img
              className="GamedayWidget-crest--light"
              src={s.logoLight}
              alt=""
              aria-hidden="true"
              loading="lazy"
              referrerpolicy="no-referrer"
            />
          ) : null}
          {s.logo ? (
            <img
              className="GamedayWidget-crest--dark"
              src={s.logo}
              alt=""
              aria-hidden="true"
              loading="lazy"
              referrerpolicy="no-referrer"
            />
          ) : null}
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
