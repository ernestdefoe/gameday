import app from 'flarum/forum/app';

declare const m: any;

/**
 * Players of the week, offered to Page Builder.
 *
 * 🚨 Page Builder is neither imported nor required, and registration goes
 * through its queue — `app.pageBuilder` does not exist until its own
 * initializer has run.
 */
export default function registerPerformers(): void {
  const component = {
    view(vnode: any) {
      const settings = vnode.attrs.settings || {};
      const data = vnode.attrs.data || {};
      const groups: any[] = data.groups || [];

      if (groups.length === 0) {
        // Box scores arrive hours after a game and only for games Game Day
        // opened a thread for. Silence is the honest answer before then.
        if (settings.hideWhenEmpty !== false) return null;

        return m('.GdPerformers', [
          settings.title ? m('h3.GdPerformers-title', settings.title) : null,
          m('p.GdPerformers-empty', 'No box scores yet this week.'),
        ]);
      }

      return m('.GdPerformers', [
        m('.GdPerformers-head', [
          settings.title ? m('h3.GdPerformers-title', settings.title) : null,
          data.week ? m('span.GdPerformers-week', data.week) : null,
        ]),

        m('.GdPerformers-groups', groups.map((g: any) => {
          const [star, ...rest] = g.players;

          /*
           * 🚨 The card takes the club's colour as a custom property rather
           * than as a style string built here. The stylesheet then decides what
           * to do with it — a gradient, a rule, a glow — and changing that look
           * is a CSS edit rather than a JavaScript one.
           */
          return m('.GdPerformers-group', {
            key: g.key,
            style: star && star.color ? `--gdp-team: ${star.color}` : undefined,
          }, [
            m('h4.GdPerformers-groupTitle', g.title),

            star ? m('.GdPerformers-star', [
              m('.GdPerformers-starBg'),
              star.crest ? m('img.GdPerformers-starCrest', { src: star.crest, alt: '', referrerpolicy: 'no-referrer' }) : null,

              m('.GdPerformers-starBody', [
                m('.GdPerformers-starStat', [
                  m('b.GdPerformers-starBig', star.big || '—'),
                  m('span.GdPerformers-starUnit', star.bigLabel || ''),
                ]),
                m('.GdPerformers-starName', star.name),
                m('.GdPerformers-starMeta', [
                  star.teamAbbr || star.team,
                  m('span.GdPerformers-cat', star.label),
                ]),
                m('.GdPerformers-starLine', star.line),
              ]),

              // The cutout bleeds off the bottom edge; see the stylesheet.
              star.photo
                ? m('img.GdPerformers-starPhoto', { src: star.photo, alt: '', referrerpolicy: 'no-referrer' })
                : null,
            ]) : null,

            rest.length
              ? m('ol.GdPerformers-list', rest.map((p: any) =>
                  m('li.GdPerformers-row', { key: p.rank }, [
                    m('span.GdPerformers-rank', p.rank),

                    m('span.GdPerformers-face', [
                      p.photo
                        ? m('img.GdPerformers-photo', { src: p.photo, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' })
                        : m('span.GdPerformers-photo.GdPerformers-photo--none',
                            String(p.name || '?').charAt(0).toUpperCase()),
                      p.crest
                        ? m('img.GdPerformers-crest', { src: p.crest, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' })
                        : null,
                    ]),

                    m('span.GdPerformers-who', [
                      m('span.GdPerformers-name', p.name),
                      m('span.GdPerformers-meta', [
                        p.teamAbbr || p.team,
                        m('span.GdPerformers-cat', p.label),
                      ]),
                    ]),

                    m('span.GdPerformers-line', p.line),
                  ])
                ))
              : null,
          ]);
        })),
      ]);
    },
  };

  const registry = (app as any).pageBuilder;

  if (registry && typeof registry.registerBlock === 'function') {
    registry.registerBlock('gameday-performers', component);
    return;
  }

  const queue = ((window as any).PageBuilderBlockQueue = (window as any).PageBuilderBlockQueue || []);
  queue.push({ type: 'gameday-performers', component });
}
