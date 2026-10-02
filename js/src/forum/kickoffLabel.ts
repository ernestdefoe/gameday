/**
 * The kickoff as a board prints it: "Sat 3 Oct, 2:30 pm" in the reader's zone.
 *
 * 🚨 A kickoff whose time is not announced yet arrives as a placeholder of
 * midnight Eastern on game day. Formatted like a real time it reads "Fri 2 Oct,
 * 11:00 pm" in Central — the wrong day and a time nobody set. So an unannounced
 * one is the DATE, read in the zone the placeholder was written in, and the
 * words "time TBA".
 */
export default function kickoffLabel(kickoff: string, tbd: boolean, tba: string): string {
  const at = new Date(kickoff);

  if (tbd) {
    const day = at.toLocaleDateString(undefined, {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      timeZone: 'America/New_York',
    });

    return `${day}, ${tba}`;
  }

  return at.toLocaleString(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  });
}
