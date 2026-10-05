import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import Icon from 'flarum/common/components/Icon';

declare const m: any;

export interface Channel {
  key: string;
  label: string;
  kind: 'tv' | 'streaming' | 'radio';
  market: 'national' | 'home' | 'away';
  /** The team whose local market this is; empty for a national listing. */
  team: string;
  subscription: boolean;
  url: string;
}

export interface Watch {
  channels: Channel[];
  watch: { url: string; label: string; subscription: boolean } | null;
  /** A finished game: the chips stay as a record, the button goes. */
  compact: boolean;
}

const t = (k: string, p?: any) => app.translator.trans(`ernestdefoe-gameday.forum.watch_${k}`, p);

const KIND_ICON: Record<string, string> = {
  tv: 'fas fa-tv',
  streaming: 'fas fa-play',
  radio: 'fas fa-radio',
};

/**
 * One network as a chip.
 *
 * 🚨 Styled text, not logos. A network's mark is its trademark, and nothing
 * here has permission to bundle one — a recognisable name in a consistent chip
 * reads at a glance anyway, and never goes stale when a network redraws its
 * logo. The medium is an icon WITH a label behind it, never the icon alone.
 */
function chip(c: Channel) {
  const kind = extractText(t(`kind_${c.kind}`));
  const local = c.market !== 'national' && c.team ? extractText(t('local', { team: c.team })) : '';
  const sub = c.subscription ? extractText(t('subscription')) : '';
  const title = [c.label, kind, local, sub].filter(Boolean).join(' · ');

  return (
    <li className={`GamedayWatch-chip GamedayWatch-chip--${c.kind}${c.market !== 'national' ? ' GamedayWatch-chip--local' : ''}`} title={title}>
      <Icon name={KIND_ICON[c.kind] || KIND_ICON.tv} className="GamedayWatch-kind" />
      <span className="sr-only">{kind}: </span>
      <span className="GamedayWatch-name">{c.label}</span>
      {local ? <span className="GamedayWatch-local">{local}</span> : null}
      {sub ? <span className="GamedayWatch-sub">{sub}</span> : null}
    </li>
  );
}

/**
 * The chips and, before the final whistle, the button.
 *
 * `withButton` is false in a widget strip holding several games: six Watch
 * buttons in a row of cards is a wall of calls to action, and the thread a
 * card links to has the button.
 */
export function watchRow(w: Watch | null | undefined, className: string, withButton = true) {
  if (!w || !w.channels || w.channels.length === 0) return null;

  const button = withButton && !w.compact && w.watch ? w.watch : null;

  return (
    <div className={`GamedayWatch ${className}${w.compact ? ' GamedayWatch--compact' : ''}`}>
      <span className="GamedayWatch-caption">{t(w.compact ? 'caption_final' : 'caption')}</span>
      <ul className="GamedayWatch-chips">{w.channels.map(chip)}</ul>
      {button ? (
        <a
          className="GamedayWatch-button"
          href={button.url}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={extractText(t('button_label', { network: button.label }))}
        >
          <Icon name="fas fa-play" className="GamedayWatch-buttonIcon" />
          <span>{t('button', { network: button.label })}</span>
          <Icon name="fas fa-arrow-up-right-from-square" className="GamedayWatch-external" />
        </a>
      ) : null}
    </div>
  );
}
