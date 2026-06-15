import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';

/**
 * RatingListDisplaySettings — the discussion-list rating display options.
 *
 * Per-device style selects (desktop/mobile) plus CASCADING extras that only
 * appear when a single-star ("compact") style is chosen on either device:
 *   - single_clickable:  the star+number opens the ratings modal on click
 *   - single_modal_rate: (only when clickable) the modal also lets users rate
 *
 * Immediate-apply (POST /api/settings) like AvatarSettings, so the reveal is
 * reactive as the admin changes the dropdowns — no Save round-trip needed.
 */

const STYLE_KEYS = {
  desktop: 'tryhackx-topic-rating.list_style_desktop',
  mobile: 'tryhackx-topic-rating.list_style_mobile',
};
const CLICKABLE_KEY = 'tryhackx-topic-rating.single_clickable';
const MODAL_RATE_KEY = 'tryhackx-topic-rating.single_modal_rate';

const STYLES = ['full_meta', 'single_filled_meta', 'single_bucket_meta', 'full_title', 'single_filled_title', 'single_bucket_title'];

export default class RatingListDisplaySettings extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.saving = {};
    this.error = null;
  }

  styleOptions() {
    const o = {};
    STYLES.forEach((s) => {
      o[s] = app.translator.trans('tryhackx-topic-rating.admin.settings.list_style_' + s);
    });
    return o;
  }

  currentStyle(device) {
    const v = app.data.settings[STYLE_KEYS[device]];
    return STYLES.indexOf(v) !== -1 ? v : 'full_meta';
  }

  isSingle(style) {
    return style.indexOf('single_') === 0;
  }

  boolSetting(key, def) {
    const v = app.data.settings[key];
    if (v === undefined || v === null) return def;
    return v !== false && v !== '0' && v !== 0 && v !== 'false';
  }

  view() {
    const dStyle = this.currentStyle('desktop');
    const mStyle = this.currentStyle('mobile');
    const anySingle = this.isSingle(dStyle) || this.isSingle(mStyle);
    const clickable = this.boolSetting(CLICKABLE_KEY, true);
    const modalRate = this.boolSetting(MODAL_RATE_KEY, false);

    return (
      <div className="RatingListDisplaySettings">
        <h3 className="TopicRating-settingHeading">
          {app.translator.trans('tryhackx-topic-rating.admin.settings.list_display_heading')}
        </h3>

        {this.error && <div className="AvatarSettings-error">{this.error}</div>}

        {this.renderStyleField('desktop', 'fas fa-desktop')}
        {this.renderStyleField('mobile', 'fas fa-mobile-alt')}

        {anySingle && (
          <div className="RatingListDisplaySettings-cascade">
            <div className="Form-group">
              <Switch
                state={clickable}
                disabled={!!this.saving[CLICKABLE_KEY]}
                onchange={(v) => this.saveBool(CLICKABLE_KEY, v)}
              >
                {app.translator.trans('tryhackx-topic-rating.admin.settings.single_clickable_label')}
              </Switch>
              <div className="helpText">
                {app.translator.trans('tryhackx-topic-rating.admin.settings.single_clickable_help')}
              </div>
            </div>

            {clickable && (
              <div className="Form-group RatingListDisplaySettings-subCascade">
                <Switch
                  state={modalRate}
                  disabled={!!this.saving[MODAL_RATE_KEY]}
                  onchange={(v) => this.saveBool(MODAL_RATE_KEY, v)}
                >
                  {app.translator.trans('tryhackx-topic-rating.admin.settings.single_modal_rate_label')}
                </Switch>
                <div className="helpText">
                  {app.translator.trans('tryhackx-topic-rating.admin.settings.single_modal_rate_help')}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    );
  }

  renderStyleField(device, icon) {
    const value = this.currentStyle(device);

    return (
      <div className="Form-group">
        <label>
          <i className={icon + ' RatingListDisplaySettings-deviceIcon'} />{' '}
          {app.translator.trans('tryhackx-topic-rating.admin.settings.list_style_' + device + '_label')}
        </label>
        <div className="helpText">
          {app.translator.trans('tryhackx-topic-rating.admin.settings.list_style_help')}
        </div>
        <Select
          value={value}
          options={this.styleOptions()}
          disabled={!!this.saving[STYLE_KEYS[device]]}
          onchange={(v) => this.saveValue(STYLE_KEYS[device], v)}
        />
      </div>
    );
  }

  saveValue(key, value) {
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

  saveBool(key, value) {
    // Persist as '1' / '0' to match Flarum's boolean setting convention
    // (read back server-side via boolval()).
    this.saveValue(key, value ? '1' : '0');
  }
}
