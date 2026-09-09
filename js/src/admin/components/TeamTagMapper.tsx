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
 * Every tag on the site, cached for the same reason the rows are.
 *
 * 🚨 Fetched, not read out of the store. `app.store.all('tags')` returns
 * whatever some other screen happened to load, and in the admin that is the
 * first page of the tag list — fifty tags, mostly the top-level ones. On a
 * board with a forum per school that meant the picker offered conferences and
 * not one of the team forums the games are actually meant to go in.
 */
let TAGS: TagOption[] | null = null;

interface TagOption {
  id: number;
  name: string;
  /** The parent's name, so two tags called the same thing can be told apart. */
  under: string;
}

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

    if (TAGS === null) {
      this.loadTags();
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
  tags(): TagOption[] {
    return TAGS ?? [];
  }

  /**
   * Page through the whole tag list.
   *
   * 🚨 Paged, because the endpoint caps a page at fifty however large a limit
   * is asked for. A board with a hundred and fifty forums would otherwise be
   * offered a third of them, silently, with no sign that the list was cut.
   */
  loadTags(offset = 0, carry: TagOption[] = []): void {
    const LIMIT = 50;
    const MAX_PAGES = 12;

    app
      .request<any>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/tags`,
        params: { page: { limit: LIMIT, offset }, include: 'parent' },
      })
      .then((response: any) => {
        const rows: any[] = response?.data ?? [];

        // Parent names come from the sideloaded `included` set; a tag whose
        // parent did not travel is simply shown without one.
        const names: Record<string, string> = {};
        for (const item of [...rows, ...((response?.included as any[]) ?? [])]) {
          if (item?.type === 'tags') names[String(item.id)] = item.attributes?.name ?? '';
        }

        const page = rows.map((row: any) => {
          const parent = row.relationships?.parent?.data;

          return {
            id: Number(row.id),
            name: String(row.attributes?.name ?? ''),
            under: parent ? names[String(parent.id)] ?? '' : '',
          };
        });

        const all = [...carry, ...page];

        // A short page is the last page; there is nothing more to ask for.
        if (rows.length === LIMIT && offset / LIMIT + 1 < MAX_PAGES) {
          this.loadTags(offset + LIMIT, all);

          return;
        }

        TAGS = all.sort((a, b) =>
          // Grouped by parent, then by name — which is the order somebody
          // scanning for "the Alabama forum under the SEC" reads in.
          (a.under || '\uffff').localeCompare(b.under || '\uffff') || a.name.localeCompare(b.name)
        );

        m.redraw();
      })
      .catch(() => {
        TAGS = TAGS ?? [];
        m.redraw();
      });
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
                            // "SEC › Alabama", because a board with a forum per
                            // school has several tags whose names only differ by
                            // which conference they sit in.
                            ...tags.map((tag) =>
                              m('option', { value: String(tag.id) }, tag.under ? `${tag.under} › ${tag.name}` : tag.name)
                            ),
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
