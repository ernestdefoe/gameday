/**
 * The scoreboard strip, advancing on its own.
 *
 * 🚨 It steps a CARD at a time rather than gliding continuously. A marquee is
 * the obvious way to build this and the wrong one for scores: a number that is
 * always moving is a number you have to chase to read, and these cards are
 * discrete things — a game each — so the honest motion is one game, a pause
 * long enough to read it, the next game.
 *
 * 🚨 It also stops for almost everything. An element that moves under the
 * pointer while somebody is reaching for a link is worse than one that never
 * moved, so it yields to hover, to focus, to any touch or wheel or key, to a
 * hidden tab, and to being scrolled off screen. Auto-scrolling is a convenience
 * for somebody not interacting; the moment they are, it is in the way.
 */

/** How long each game holds the strip. */
const STEP_MS = 4500;

/** How long a human touching it wins. */
const YIELD_MS = 12000;

/** How often the loop wakes to decide. Cheap, and not a frame timer. */
const TICK_MS = 500;

export function startTicker(strip: HTMLElement): void {
  // Twice on one element would be two timers racing to scroll it.
  if ((strip as any).__gdTicker) return;
  (strip as any).__gdTicker = true;

  /*
   * 🚨 Off entirely for a reader who has asked the machine to stop moving
   * things. This is precisely the kind of motion that setting exists for, and
   * "reduced" here means none rather than slower.
   */
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  let hovered = false;
  let yieldUntil = 0;
  let visible = true;
  let timer: any = null;

  const yieldNow = () => {
    yieldUntil = Date.now() + YIELD_MS;
  };

  strip.addEventListener('pointerenter', () => {
    hovered = true;
  });
  strip.addEventListener('pointerleave', () => {
    hovered = false;
  });

  // Any deliberate input hands the strip over. `focusin` covers the keyboard
  // reader tabbing into a card's link, which hover never sees.
  ['pointerdown', 'wheel', 'touchstart', 'keydown', 'focusin'].forEach((event) =>
    strip.addEventListener(event, yieldNow, { passive: true })
  );

  /*
   * 🚨 Off screen means stopped. A widget below the fold that kept stepping
   * would be a page that has quietly scrolled itself somewhere else by the
   * time the reader arrives at it — and it would do that work for nobody.
   */
  let observer: IntersectionObserver | null = null;

  if (typeof IntersectionObserver !== 'undefined') {
    observer = new IntersectionObserver(
      (entries) => {
        visible = entries.some((entry) => entry.isIntersecting);
      },
      { threshold: 0.35 }
    );
    observer.observe(strip);
  }

  const stop = () => {
    if (timer) clearInterval(timer);
    timer = null;
    if (observer) observer.disconnect();
    (strip as any).__gdTicker = false;
  };

  let waited = 0;

  timer = setInterval(() => {
    /*
     * 🚨 The element decides when this ends, not a lifecycle hook.
     *
     * Bespoke rebuilds its zone hosts on every redraw, so this strip is thrown
     * away and replaced constantly and `onremove` is not reliably called for
     * it — see boardStore. A timer that waited to be told would outlive its
     * element, and after an afternoon of redraws there would be a hundred of
     * them scrolling elements that are no longer in the document.
     */
    if (!strip.isConnected) {
      stop();

      return;
    }

    // Nothing to scroll: one card, or a narrow placement where the container
    // query has stacked them.
    const max = strip.scrollWidth - strip.clientWidth;

    if (max <= 4) return;

    if (hovered || !visible || document.hidden || Date.now() < yieldUntil) {
      waited = 0;

      return;
    }

    waited += TICK_MS;

    if (waited < STEP_MS) return;

    waited = 0;

    /*
     * The next card's left edge, measured rather than assumed: the cards are
     * sized with `clamp()` and the gap is a token, so any width arithmetic here
     * would be a second copy of the stylesheet.
     *
     * 🚨 Measured RELATIVE to the first card. `offsetLeft` is from the padding
     * box and `scrollLeft` is from the content start, so with the strip's own
     * 16px inset every card reads 16px further along than it scrolls to — and
     * the first step "advanced" to card one, which was already showing. It
     * looked like a ticker that had not started.
     */
    const cards = Array.from(strip.children) as HTMLElement[];
    const base = cards.length ? cards[0].offsetLeft : 0;

    const next = cards.find((card) => card.offsetLeft - base > strip.scrollLeft + 4);

    // Past the last card, back to the first — a loop, because a ticker that
    // stops at the end is a ticker that is blank for everyone who arrives late.
    const left = !next || next.offsetLeft - base > max ? 0 : next.offsetLeft - base;

    strip.scrollTo({ left, behavior: 'smooth' });
  }, TICK_MS);
}
