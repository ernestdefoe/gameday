/**
 * Run `fn` now if the tab is visible, or once it next becomes visible.
 * Returns a function that cancels the wait.
 *
 * 🚨 The polls on a live game (board, reactions, the scoreboard widget) are
 * a request every few seconds per reader. A tab left open in the background
 * through a whole game kept all of them going for nobody, so each poll now
 * holds its next request until somebody can see the answer.
 */
export default function whenVisible(fn: () => void): () => void {
  if (typeof document === 'undefined' || !document.hidden) {
    fn();
    return () => {};
  }

  const onChange = () => {
    if (document.hidden) return;
    document.removeEventListener('visibilitychange', onChange);
    fn();
  };

  document.addEventListener('visibilitychange', onChange);

  return () => document.removeEventListener('visibilitychange', onChange);
}
