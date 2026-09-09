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

        m('.GdPerformers-groups', groups.map((g: any) =>
          m('.GdPerformers-group', { key: g.key }, [
            m('h4.GdPerformers-groupTitle', g.title),
            m('ol.GdPerformers-list', g.players.map((p: any) =>
              m('li.GdPerformers-row', { key: p.rank, className: p.rank === 1 ? 'GdPerformers-row--top' : '' }, [
                m('span.GdPerformers-rank', p.rank),

                m('span.GdPerformers-face', [
                  p.photo
                    ? m('img.GdPerformers-photo', { src: p.photo, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' })
                    : m('span.GdPerformers-photo.GdPerformers-photo--none',
                        String(p.name || '?').charAt(0).toUpperCase()),
                  // The crest sits on the corner of the headshot, the way a
                  // broadcast lower-third does it.
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
            )),
          ])
        )),
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
