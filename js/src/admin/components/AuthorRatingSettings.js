import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * AuthorRatingSettings — how the topic author's own rating is treated
 * ("review mode"), plus an optional tag scope.
 *
 *   default      the author rates like any member (off — legacy behaviour)
 *   exclude      authors can't rate their own topics
 *   separate     the author's rating is shown apart from the community average
 *   author_only  only the author rates; everyone else sees the score read-only
 *
 * When Tags is enabled and a mode other than the default is chosen, the admin
 * can limit it to discussions carrying selected tags (e.g. a "Reviews" tag).
 *
 * Immediate-apply (POST /api/settings) like RatingListDisplaySettings, so the
 * tag picker reveals reactively. Saving either key makes the backend re-derive
 * every topic's stored score for the new mode (see RefreshEffectiveScores).
 */

const MODE_KEY = 'tryhackx-topic-rating.author_mode';
const TAGS_KEY = 'tryhackx-topic-rating.author_mode_tags';
const MODES = ['default', 'exclude', 'separate', 'author_only'];

export default class AuthorRatingSettings extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.saving = {};
    this.error = null;
    this.tags = null;
    this.loadTags();
  }

  tagsEnabled() {
    return !!(typeof flarum !== 'undefined' && flarum.extensions && flarum.extensions['flarum-tags']);
  }

  loadTags() {
    if (!this.tagsEnabled()) return;

    const done = (tags) => {
      this.tags = (Array.isArray(tags) ? tags : app.store.all('tags') || [])
        .slice()
        .sort((a, b) => (a.position() ?? 1e9) - (b.position() ?? 1e9) || String(a.name()).localeCompare(String(b.name())));
      m.redraw();
    };

    if (app.tagList && typeof app.tagList.load === 'function') {
      app.tagList.load(['parent']).then(done).catch(() => done(null));
    } else {
      done(null);
    }
  }

  currentMode() {
    const v = app.data.settings[MODE_KEY];
    return MODES.indexOf(v) !== -1 ? v : 'default';
  }

  currentTags() {
    try {
      const parsed = JSON.parse(app.data.settings[TAGS_KEY] || '[]');
      return Array.isArray(parsed) ? parsed.map(String) : [];
    } catch (e) {
      return [];
    }
  }

  modeOptions() {
    const o = {};
    MODES.forEach((mode) => {
      o[mode] = app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_' + mode);
    });
    return o;
  }

  view() {
    const mode = this.currentMode();

    return (
      <div className="AuthorRatingSettings">
        <h3 className="TopicRating-settingHeading">
          {app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_heading')}
        </h3>

        {this.error && <div className="AvatarSettings-error">{this.error}</div>}

        <div className="Form-group">
          <label>{app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_label')}</label>
          <div className="helpText">{app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_help')}</div>
          <Select
            value={mode}
            options={this.modeOptions()}
            disabled={!!this.saving[MODE_KEY]}
            onchange={(v) => this.save(MODE_KEY, v)}
          />
          <div className="helpText AuthorRatingSettings-hint">
            {app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_hint_' + mode)}
          </div>
        </div>

        {mode !== 'default' && this.tagsEnabled() && this.renderTagScope()}
      </div>
    );
  }

  renderTagScope() {
    const selected = this.currentTags();

    return (
      <div className="Form-group AuthorRatingSettings-tags">
        <label>{app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_tags_label')}</label>
        <div className="helpText">{app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_tags_help')}</div>

        {this.tags === null ? (
          <LoadingIndicator display="inline" />
        ) : this.tags.length === 0 ? (
          <span className="helpText">{app.translator.trans('tryhackx-topic-rating.admin.settings.tags_empty')}</span>
        ) : (
          <div className="BypassGroupPicker-groups">
            {this.tags.map((tag) => {
              const id = String(tag.id());
              const isSelected = selected.indexOf(id) !== -1;
              const style = {};
              if (tag.color()) {
                style.borderColor = tag.color();
                if (isSelected) {
                  style.backgroundColor = tag.color();
                  style.color = '#fff';
                }
              }

              return (
                <button
                  type="button"
                  key={id}
                  className={'BypassGroupPicker-groupPill' + (isSelected ? ' BypassGroupPicker-groupPill--selected' : '')}
                  disabled={!!this.saving[TAGS_KEY]}
                  aria-pressed={isSelected ? 'true' : 'false'}
                  style={style}
                  onclick={() => {
                    const next = isSelected ? selected.filter((x) => x !== id) : selected.concat(id);
                    this.save(TAGS_KEY, JSON.stringify(next));
                  }}
                >
                  {tag.icon() && <i className={tag.icon() + ' BypassGroupPicker-groupPillIcon'}></i>}
                  <span>{tag.name()}</span>
                  {isSelected && <i className="fas fa-check BypassGroupPicker-groupCheck"></i>}
                </button>
              );
            })}
          </div>
        )}

        <div className="helpText AuthorRatingSettings-hint">
          {selected.length
            ? app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_tags_some', { count: selected.length })
            : app.translator.trans('tryhackx-topic-rating.admin.settings.author_mode_tags_none')}
        </div>
      </div>
    );
  }

  save(key, value) {
    if (this.saving[key]) return;
    this.saving[key] = true;
    this.error = null;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/settings',
        body: { [key]: value },
      })
      .then(() => {
        app.data.settings[key] = value;
        this.saving[key] = false;
        m.redraw();
      })
      .catch((err) => {
        this.error =
          (err && err.alert && err.alert.content) ||
          app.translator.trans('tryhackx-avatars.admin.save_error');
        this.saving[key] = false;
        m.redraw();
      });
  }
}
