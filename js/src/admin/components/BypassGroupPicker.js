import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';

export default class BypassGroupPicker extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.selectedGroups = this.parseValue();
        this.groups = [];
        this.loadGroups();
    }

    loadGroups() {
        const all = app.store.all('groups') || [];
        // Drop the Guest pseudo-group — guests can't be logged in to rate.
        this.groups = all.filter((g) => g.id() !== '2');
    }

    parseValue() {
        try {
            const v = this.attrs.settingValue();
            if (!v) return ['1'];
            const parsed = JSON.parse(v);
            return Array.isArray(parsed) ? parsed.map(String) : [];
        } catch (_) {
            return ['1'];
        }
    }

    persist(nextSelected) {
        this.attrs.settingValue(JSON.stringify(nextSelected));
    }

    view() {
        const label = this.attrs.label ? <label>{this.attrs.label}</label> : null;
        const help = this.attrs.help ? <p className="helpText">{this.attrs.help}</p> : null;

        const allSelected = this.groups.length > 0 && this.groups.every(g => this.selectedGroups.includes(String(g.id())));
        const noneSelected = this.selectedGroups.length === 0;

        return (
            <div className="Form-group BypassGroupPicker">
                {label}
                {help}
                {this.groups.length > 0 && (
                    <div className="BypassGroupPicker-toolbar">
                        <button
                            type="button"
                            className="Button Button--text BypassGroupPicker-toolBtn"
                            disabled={allSelected}
                            onclick={() => {
                                const next = this.groups.map(g => String(g.id()));
                                this.selectedGroups = next;
                                this.persist(next);
                            }}
                        >
                            <i className="fas fa-check-double"></i>{' '}
                            {app.translator.trans('tryhackx-topic-rating.admin.settings.bypass_select_all', {}, true)}
                        </button>
                        <button
                            type="button"
                            className="Button Button--text BypassGroupPicker-toolBtn"
                            disabled={noneSelected}
                            onclick={() => {
                                this.selectedGroups = [];
                                this.persist([]);
                            }}
                        >
                            <i className="fas fa-times"></i>{' '}
                            {app.translator.trans('tryhackx-topic-rating.admin.settings.bypass_clear_all', {}, true)}
                        </button>
                    </div>
                )}
                <div className="BypassGroupPicker-groups">
                    {this.groups.length === 0
                        ? <span className="helpText">{app.translator.trans('tryhackx-topic-rating.admin.settings.tag_no_groups', {}, true)}</span>
                        : this.groups.map((g) => {
                            const gid = String(g.id());
                            const isSelected = this.selectedGroups.includes(gid);
                            const pillStyle = {};
                            if (g.color()) {
                                pillStyle.borderColor = g.color();
                                if (isSelected) {
                                    pillStyle.backgroundColor = g.color();
                                    pillStyle.color = '#fff';
                                }
                            }
                            return (
                                <button
                                    type="button"
                                    key={gid}
                                    className={'BypassGroupPicker-groupPill' + (isSelected ? ' BypassGroupPicker-groupPill--selected' : '')}
                                    onclick={() => {
                                        const next = isSelected
                                            ? this.selectedGroups.filter((x) => x !== gid)
                                            : this.selectedGroups.concat(gid);
                                        this.selectedGroups = next;
                                        this.persist(next);
                                    }}
                                    style={pillStyle}
                                >
                                    {g.icon() && <i className={g.icon() + ' BypassGroupPicker-groupPillIcon'}></i>}
                                    <span>{g.namePlural() || g.nameSingular()}</span>
                                    {isSelected && <i className="fas fa-check BypassGroupPicker-groupCheck"></i>}
                                </button>
                            );
                        })
                    }
                </div>
            </div>
        );
    }
}
