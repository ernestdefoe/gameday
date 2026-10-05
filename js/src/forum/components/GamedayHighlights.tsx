import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import Icon from 'flarum/common/components/Icon';

declare const m: any;

export interface Clip {
  id: string;
  title: string;
  duration: number;
  image: string;
}

const t = (k: string, p?: any) => app.translator.trans(`ernestdefoe-gameday.forum.highlights_${k}`, p);

/** 71 → "1:11". */
function duration(seconds: number): string {
  if (!seconds || seconds < 1) return '';
  const s = Math.round(seconds);
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/**
 * A finished game's highlight clips, under its scoreboard.
 *
 * 🚨 Played in ESPN's own syndicated player — the same one Roster's player
 * pages use — and that player is built only when somebody presses play. Six
 * ESPN players on a page is six heavyweight embeds loading before anyone has
 * chosen a clip; a thumbnail costs one image.
 *
 * 🚨 The player URL is built from the clip ID and nothing else, and the ID is
 * digits — checked by Picks when it is stored, by the board when it is served,
 * and once more here. Nothing a feed sends is ever handed to an iframe as-is.
 *
 * 🚨 Here, in the thread's own component, and never in the recap post. Post
 * content is parsed text; an embed written into it is either escaped into noise
 * or trusted as markup, and a clip arriving an hour after the final would mean
 * rewriting somebody's post to add it.
 */
export default class GamedayHighlights extends Component<{ clips: Clip[] }> {
  playing: string | null = null;

  view() {
    const clips = (this.attrs.clips || []).filter((c) => /^\d+$/.test(String(c.id)));
    if (clips.length === 0) return null;

    return (
      <section className="GamedayHighlights" aria-label={extractText(t('title'))}>
        <h3 className="GamedayHighlights-title">
          <Icon name="fas fa-film" />
          {t('title')}
        </h3>
        <div className="GamedayHighlights-strip">{clips.map((c) => this.clip(c))}</div>
      </section>
    );
  }

  clip(c: Clip) {
    const time = duration(c.duration);

    return (
      <figure className="GamedayClip" key={c.id}>
        {this.playing === c.id ? (
          <div className="GamedayClip-frame">
            <iframe
              src={`https://www.espn.com/core/video/iframe/_/id/${c.id}/`}
              title={c.title}
              allow="autoplay; fullscreen; encrypted-media; picture-in-picture"
              allowfullscreen
              loading="lazy"
              referrerpolicy="strict-origin-when-cross-origin"
            />
          </div>
        ) : (
          <button
            type="button"
            className="GamedayClip-poster"
            aria-label={extractText(t('play', { title: c.title }))}
            onclick={() => {
              this.playing = c.id;
            }}
          >
            {c.image ? <img src={c.image} alt="" loading="lazy" referrerpolicy="no-referrer" /> : null}
            <span className="GamedayClip-play" aria-hidden="true">
              <Icon name="fas fa-play" />
            </span>
            {time ? <span className="GamedayClip-time">{time}</span> : null}
          </button>
        )}
        <figcaption className="GamedayClip-caption">{c.title}</figcaption>
      </figure>
    );
  }
}
