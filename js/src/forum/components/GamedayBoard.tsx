import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import kickoffLabel from '../kickoffLabel';
import LiveReactions from './LiveReactions';
import whenVisible from '../whenVisible';
import GamedayHighlights, { type Clip } from './GamedayHighlights';
import { watchRow, type Watch } from '../whereToWatch';
import crestUrl from '../crest';

declare const m: any;

interface Side {
  name: string;
  abbr: string;
  rank: number | null;
  /** What they brought into this game — "2-0". Frozen at kickoff. */
  record: string;
  logo: string;
  score: number | null;
  hasBall: boolean;
}

interface Board {
  id: number;
  state: 'scheduled' | 'live' | 'final';
  periodLine: string;
  clock: string | null;
  possession: 'home' | 'away' | null;
  down: string | null;
  ballOn: string | null;
  redZone: boolean;
  clockStale: boolean;
  kickoff: string | null;
  /** The date is set, the time is not announced yet. */
  kickoffTbd?: boolean;
  venue: string;
  venueCity: string;
  broadcast: string;
  /** Where it is on — null when nothing is listed or the feature is off. */
  watch?: Watch | null;
  /** A finished game's clips; empty otherwise. */
  highlights?: Clip[];
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
  cancelWait: (() => void) | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);

    // Straight off the discussion, so the board is there when the thread is
    // rather than arriving a beat later and shoving the first post down.
    try {
      this.board = this.attrs.discussion.attribute('gamedayBoard') || null;
    } catch (e) {
      this.board = null;
    }

    this.schedule();
  }

  onremove() {
    if (this.timer) clearTimeout(this.timer);
    if (this.cancelWait) this.cancelWait();
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

    // Held while the tab is hidden: see whenVisible.
    this.timer = setTimeout(() => {
      this.cancelWait = whenVisible(() => this.refresh());
    }, 15000);
  }

  refresh() {
    app
      .request<{ board: Board | null }>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/gameday/board/${this.attrs.discussion.id()}`,
      })
      .then((res) => {
        if (res && res.board) this.board = res.board;
        m.redraw();
      })
      .catch(() => {
        /* a missed poll is a stale board, not a broken page */
      })
      .then(() => this.schedule());
  }

  view() {
    const b = this.board;
    if (!b) return null;

    const t = (k: string, p?: any) => app.translator.trans(`ernestdefoe-gameday.forum.board_${k}`, p);

    return [
      <div className={`GamedayBoard GamedayBoard--${b.state}${b.redZone ? ' GamedayBoard--redzone' : ''}`}>
        <div className="GamedayBoard-strip">
          {this.side(b.away)}

          <div className="GamedayBoard-middle">
            <span className="GamedayBoard-period">{b.periodLine || t('scheduled')}</span>
            {b.clock ? <span className="GamedayBoard-clock">{b.clock}</span> : null}
            {/* Said out loud. A board that silently drops its clock looks
                broken in exactly the moment somebody is watching it. */}
            {b.clockStale ? <span className="GamedayBoard-stale">{t('stale')}</span> : null}
            {b.state === 'scheduled' && b.kickoff ? (
              <span className="GamedayBoard-kickoff">{kickoffLabel(b.kickoff, !!b.kickoffTbd, extractText(t('time_tba')))}</span>
            ) : null}
          </div>

          {this.side(b.home)}
        </div>

        {/* 🚨 Only while the game is being played. A floating emoji says
            "this is happening now"; on Saturday's finished game it says
            nothing at all. */}
        {b.state === 'live' ? m(LiveReactions, { discussion: this.attrs.discussion }) : null}

        {b.down || b.ballOn || b.redZone ? (
          <div className="GamedayBoard-situation">
            {b.down ? <span className="GamedayBoard-down">{b.down}</span> : null}
            {/* Said after the down, the way it is said out loud. */}
            {b.ballOn ? <span className="GamedayBoard-ballOn">{b.ballOn}</span> : null}
            {b.redZone ? <span className="GamedayBoard-redzone">{t('red_zone')}</span> : null}
          </div>
        ) : null}

        {/* 🚨 Gated on the STATE, not simply on the situation row being absent.
            The feed drops the down between plays, so "whenever there is no
            down" would swap the venue in and out every few seconds while a game
            was on — a board that changes height under somebody watching it. */}
        {b.state === 'live' ? null : this.meta(b)}

        {/* 🚨 Inside the card, and in every state. Before kickoff and while it
            is on, this is the question the thread is asked first; after the
            final it shrinks to a record of where it was shown. Its own row so
            it does not come and go with the venue line during play. */}
        {watchRow(b.watch, 'GamedayBoard-watch')}
      </div>,

      b.state === 'final' && b.highlights && b.highlights.length ? m(GamedayHighlights, { clips: b.highlights }) : null,
    ];
  }

  /**
   * Where it is being played and who is showing it.
   *
   * 🚨 Never while the game is being played, which is where the situation row
   * lives. That is the right trade on its own terms — what down it is beats
   * what stadium it is in, for the ninety minutes anybody cares — and it is
   * also what keeps the board from changing height between plays.
   *
   * Before kickoff it is the opposite: the board is two crests, two dashes and
   * a time, most of the people who will ever see it are seeing it now, and the
   * stadium and the channel are the whole of what is left to ask.
   */
  meta(b: Board) {
    const where = [b.venue, b.venueCity].filter(Boolean).join(', ');
    // The channel moves to the watch row when there is one; otherwise it stays here.
    const tv = b.watch ? '' : b.broadcast;
    const parts = [where, tv].filter(Boolean);

    // Nothing known is no row. An empty rule across the bottom of the board
    // reads as something that failed to load.
    if (parts.length === 0) return null;

    return (
      <div className="GamedayBoard-meta">
        {where ? <span className="GamedayBoard-venue">{where}</span> : null}
        {tv ? <span className="GamedayBoard-tv">{tv}</span> : null}
      </div>
    );
  }

  side(s: Side) {
    return (
      <div className={`GamedayBoard-side${s.hasBall ? ' GamedayBoard-side--ball' : ''}`}>
        <span className="GamedayBoard-crest">
          {s.logo ? <img src={crestUrl(s.logo, 42)} alt="" aria-hidden="true" loading="lazy" decoding="async" referrerpolicy="no-referrer" /> : null}
        </span>
        <span className="GamedayBoard-team">
          {/*
            🚨 The rank and the name share an element of their own, rather than
            being two children of the block with the record.

            Wrapping was tried and is wrong: on a phone the name column is
            narrow enough that "#12" breaks onto a line of its own, so the away
            side becomes three lines against the home side's two and the strip
            stops being symmetrical. A rank is part of how the team is named
            here — it should break with the name or not at all.
          */}
          <span className="GamedayBoard-line">
            {/* Before the name, which is the only place it can go: "#12 Alabama"
                is what the team is called on a Saturday, and a rank trailing
                after the name reads as a score. */}
            {s.rank ? (
              <span className="GamedayBoard-rank" title={extractText(app.translator.trans('ernestdefoe-gameday.forum.board_rank', { rank: s.rank }))}>
                #{s.rank}
              </span>
            ) : null}
            {/* The abbreviation on a narrow screen, the name where there is room
                — one element, so the two never disagree about which is showing. */}
            <span className="GamedayBoard-name">{s.name}</span>
            <span className="GamedayBoard-abbr">{s.abbr || s.name}</span>
          </span>

          {/* 🚨 Under the name, in the small dim type — which is what stops it
              competing with the score. A record is context for the name above
              it, never a number to be read across the board, and set anywhere
              near the score's weight it would be mistaken for one. */}
          {s.record ? <span className="GamedayBoard-record">{s.record}</span> : null}
        </span>
        {/* 🚨 A marker with a label behind it, not a coloured dot. Possession is
            the one thing on this board somebody reads at a glance, and colour
            alone would put it out of reach of the readers who need the glance
            most. */}
        {s.hasBall ? (
          <span
            className="GamedayBoard-ball"
            /* 🚨 extractText(trans(...)), never transText(). Flarum 2 has no
                 transText — the same missing-method family as transChoice, and
                 it fails the same way: it throws inside view(), Mithril stops,
                 and the page sits on a spinner with a correct API response
                 already in hand and nothing in the console. */
            title={extractText(app.translator.trans('ernestdefoe-gameday.forum.board_possession', { team: s.name }))}
          >
            ●
          </span>
        ) : null}
        <span className="GamedayBoard-score">{s.score === null ? '–' : s.score}</span>
      </div>
    );
  }
}
