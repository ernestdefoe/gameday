import app from 'flarum/forum/app';

declare const m: any;

/**
 * The scoreboard's data, held OUTSIDE the component.
 *
 * 🚨 This exists because a widget is not mounted once. Bespoke rebuilds its
 * zone hosts on every redraw — it has to, because Mithril's own redraw wipes
 * the elements it appended — so a widget placed in a zone is re-created from
 * scratch each time anything on the page changes. A component that fetched in
 * `oninit` and called `m.redraw()` when the answer came back therefore fetched,
 * redrew, was rebuilt, fetched again, and did not stop: eighty requests in a
 * few seconds, and a widget that never finished rendering because it was never
 * alive long enough to.
 *
 * So the data, the timer and the in-flight request live here, where a remount
 * cannot touch them, and the component is a reader. It is also why the poll is
 * one timer for the page rather than one per placement: a scoreboard in the
 * sidebar and another above the list are two views of the same fact.
 */

export interface Side {
  name: string;
  abbr: string;
  /** The dark-ground crest. */
  logo: string;
  /** The light-ground crest. See Scoreboard::side for why both travel. */
  logoLight: string;
  score: number | null;
  hasBall: boolean;
}

export interface Link {
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

/** Nothing asked yet | asking | answered. */
type Status = 'idle' | 'loading' | 'ready';

let boards: WidgetBoard[] = [];
let status: Status = 'idle';
let timer: any = null;
let readers = 0;
let inFlight: Promise<void> | null = null;

export function currentBoards(): WidgetBoard[] {
  return boards;
}

export function isLoading(): boolean {
  return status === 'loading' && boards.length === 0;
}

/**
 * Seed from a host that already resolved the data server-side (Page Builder).
 *
 * 🚨 Only ever seeds, never clears. Two placements can disagree about whether
 * they were handed data — a Page Builder block was, a Bespoke widget on the
 * same page was not — and letting the second one overwrite the first with an
 * empty list is how a full scoreboard blanks a moment after it draws.
 */
export function seed(given: WidgetBoard[]): void {
  if (status === 'ready') return;

  boards = given;
  status = 'ready';
  schedule();
}

/** A component is on screen. */
export function attach(): void {
  readers++;

  if (status === 'idle') {
    status = 'loading';
    fetchBoards();

    return;
  }

  schedule();
}

/**
 * A component has gone.
 *
 * 🚨 Counted, not assumed. A remount is a detach followed immediately by an
 * attach, so stopping the timer on the first detach would stop it on every
 * redraw — and stopping it only when the LAST reader leaves is what lets the
 * poll survive the rebuild that caused all this.
 */
export function detach(): void {
  readers = Math.max(0, readers - 1);

  if (readers === 0 && timer) {
    clearTimeout(timer);
    timer = null;
  }
}

/**
 * How often to ask again.
 *
 * 🚨 Every fifteen seconds while a game is being PLAYED, and almost never
 * otherwise. This can sit on a page every visitor loads, so a poll that ran
 * regardless would be a request per reader per interval, all year, to be told
 * the same thing.
 *
 * 🚨 Paced by the FIRST game, which is the most urgent by construction — one
 * being played sorts ahead of one that has not started. Pacing by the whole
 * strip would put a Saturday of scheduled fixtures on the live cadence because
 * one of the ten had kicked off.
 *
 * A scheduled game still needs a poll or the widget shows "Kickoff 7:30pm"
 * through the whole first quarter to anybody who left the tab open — but only
 * inside the half hour before kickoff, which is the only window in which the
 * answer can change.
 */
function schedule(): void {
  if (timer) clearTimeout(timer);
  timer = null;

  if (readers === 0) return;

  const lead = boards[0];
  if (!lead) return;

  if (lead.state === 'live') {
    timer = setTimeout(fetchBoards, 15000);

    return;
  }

  if (lead.state === 'scheduled' && nearKickoff(lead)) {
    timer = setTimeout(fetchBoards, 60000);
  }
}

function nearKickoff(board: WidgetBoard): boolean {
  if (!board.kickoff) return false;

  // Already past kickoff counts: the feed can be a minute or two behind the
  // whistle, and that is exactly when somebody is watching this.
  return new Date(board.kickoff).getTime() - Date.now() < 1800000;
}

function fetchBoards(): Promise<void> {
  // 🚨 One request, however many placements asked. Two widgets on a page are
  // two views of one scoreboard, not two scoreboards.
  if (inFlight) return inFlight;

  inFlight = app
    .request<{ boards: WidgetBoard[] }>({
      method: 'GET',
      url: `${app.forum.attribute('apiUrl')}/gameday/board`,
    })
    .then((res: any) => {
      boards = (res && res.boards) || [];
      status = 'ready';
    })
    .catch(() => {
      // A missed poll is a stale board, not a broken page. The first failure is
      // different — it leaves nothing to draw, and the widget's empty state is
      // what says so.
      status = 'ready';
    })
    .then(() => {
      inFlight = null;
      schedule();
      m.redraw();
    });

  return inFlight;
}
