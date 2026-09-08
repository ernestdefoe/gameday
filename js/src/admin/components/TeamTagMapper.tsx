import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

declare const m: any;
const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-gameday.admin.tags.' + k, p);

interface Row {
  id: number;
  name: string;
  conference: string;
  tagId: number;
}

/*
 * 🚨 Module-level, and it is load-bearing rather than an optimisation.
 *
 * The settings page re-runs `customSetting`'s `() => m(TeamTagMapper)` on every
 * redraw, which REMOUNTS this component. Fetching in `oninit` and redrawing
 * when the answer arrives would therefore loop for ever — a perpetual spinner
 * and a flood of requests. Caching the loaded rows means a remounted instance
 * has them instantly and never asks again. The same trap is documented in the
 * Calendar extension's category manager, which paid for it first.
 */
let CACHE: Row[] | null = null;

/**
 * Which tag each team's games are posted in.
 *
 * Talks straight to the JSON API rather than through store models: there is one
 * screen that needs this and one shape it needs, and a model would be three
 * files to say the same thing.
 */
export default class TeamTagMapper extends Component {
  loading = CACHE === null;
  saving = false;
  saved = false;
  rows: Row[] = CACHE ?? [];

  oninit(vnode: any) {
    super.oninit(vnode);

    if (CACHE === null) {
      this.load();
    }
  }

  load() {
    app
      .request<{ data: Row[] }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/gameday/team-tags',
      })
      .then((response) => {
        CACHE = response.data ?? [];
        this.rows = CACHE;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /** Every tag on the site, for the picker. */
  tags(): { id: number; name: string }[] {
    return app.store
      .all<any>('tags')
      .map((tag: any) => ({ id: Number(tag.id()), name: tag.name() as string }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }

  save() {
    this.saving = true;
    this.saved = false;

    const mapping: Record<number, number> = {};
    this.rows.forEach((row) => (mapping[row.id] = row.tagId));

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/gameday/team-tags',
        body: { mapping },
      })
      .then(() => {
        this.saving = false;
        this.saved = true;
        m.redraw();
      })
      .catch(() => {
        this.saving = false;
        m.redraw();
      });
  }

  view() {
    const tags = this.tags();

    return m('.Form-group.GamedayTagMapper', [
      m('label', t('title')),
      m('.helpText', t('description')),

      this.loading
        ? m('.GamedayTagMapper-loading', [LoadingIndicator.component({ size: 'small' }), ' ', t('loading')])
        : this.rows.length === 0
          ? m('.helpText', t('empty'))
          : [
              m('table.GamedayTagMapper-table', [
                m('thead', m('tr', [m('th', t('team')), m('th', t('conference')), m('th', t('tag'))])),
                m(
                  'tbody',
                  this.rows.map((row) =>
                    m('tr', { key: row.id }, [
                      m('td', row.name),
                      m('td', row.conference || '—'),
                      m(
                        'td',
                        m(
                          'select.FormControl',
                          {
                            value: String(row.tagId || 0),
                            onchange: (event: any) => {
                              row.tagId = Number(event.target.value) || 0;
                              this.saved = false;
                            },
                          },
                          [
                            // 0 is "no tag of its own", which the server stores
                            // by deleting the row rather than writing a zero.
                            m('option', { value: '0' }, t('none')),
                            ...tags.map((tag) => m('option', { value: String(tag.id) }, tag.name)),
                          ]
                        )
                      ),
                    ])
                  )
                ),
              ]),
              m('.GamedayTagMapper-actions', [
                Button.component(
                  {
                    className: 'Button Button--primary',
                    loading: this.saving,
                    disabled: this.saving,
                    onclick: () => this.save(),
                  },
                  t('save')
                ),
                this.saved ? m('span.GamedayTagMapper-saved', ' ' + t('saved')) : null,
              ]),
            ],
    ]);
  }
}
