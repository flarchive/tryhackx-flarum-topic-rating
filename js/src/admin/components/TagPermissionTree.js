import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

const STATE_ENABLED  = 'enabled';
const STATE_DISABLED = 'disabled';
const STATE_GROUPS   = 'groups';
const STATE_INHERIT  = 'inherit';

export default class TagPermissionTree extends Component {
    oninit(vnode) {
        super.oninit(vnode);

        this.state = this.parseValue();
        this.expanded = {};
        this.secondaryExpanded = false; // collapsed on load — expand to configure
        this.loaded = false;
        this.loadError = null;
        this.tags = [];
        this.groups = [];

        this.loadGroups();

        if (app.tagList && typeof app.tagList.load === 'function') {
            app.tagList.load(['parent']).then((tags) => {
                this.tags = Array.isArray(tags) ? tags : [];
                this.loaded = true;
                m.redraw();
            }).catch((err) => {
                console.error('[topic-rating] Failed to load tags', err);
                this.tags = app.store.all('tags') || [];
                this.loadError = err;
                this.loaded = true;
                m.redraw();
            });
        } else {
            this.tags = app.store.all('tags') || [];
            this.loaded = true;
        }
    }

    loadGroups() {
        const all = app.store.all('groups') || [];
        // Drop the Guest pseudo-group — guests can't be logged in to rate.
        this.groups = all.filter((g) => g.id() !== '2');
    }

    parseValue() {
        try {
            const v = this.attrs.settingValue();
            if (!v) return {};
            const parsed = JSON.parse(v);
            return (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
        } catch (_) {
            return {};
        }
    }

    defaultStateFor(tag, parentTag) {
        return parentTag ? STATE_INHERIT : STATE_ENABLED;
    }

    findTag(id) {
        const key = String(id);
        return this.tags.find((t) => String(t.id()) === key);
    }

    persist() {
        const cleaned = {};
        for (const id of Object.keys(this.state)) {
            const entry = this.state[id];
            if (!entry || !entry.state) continue;

            const parts = id.split('_');
            let defaultState;
            if (parts.length === 2) {
                defaultState = STATE_INHERIT; // primary_secondary override
            } else {
                // Bare key: a primary tag defaults to Enabled, but a STANDALONE
                // secondary tag (the "used on their own" list) defaults to
                // Disabled — so only an explicit "Enabled" is persisted there.
                const tag = this.findTag(id);
                defaultState = (tag && !tag.isPrimary()) ? STATE_DISABLED : STATE_ENABLED;
            }

            if (entry.state === defaultState) continue;
            if (entry.state === STATE_GROUPS && (!entry.groups || entry.groups.length === 0)) continue;
            cleaned[id] = entry;
        }
        this.attrs.settingValue(JSON.stringify(cleaned));
    }

    updateEntry(key, entry) {
        this.state[key] = entry;
        this.persist();
        m.redraw();
    }

    rootTags() {
        return this.tags
            .filter((t) => t.isPrimary())
            .sort((a, b) => (a.position() || 0) - (b.position() || 0) || a.name().localeCompare(b.name()));
    }

    childrenOf(tag) {
        // Under a primary tag, the "subtags" are all secondary tags
        return this.tags
            .filter((t) => !t.isPrimary())
            .sort((a, b) => (a.position() || 0) - (b.position() || 0) || a.name().localeCompare(b.name()));
    }

    getEntry(tag, parentTag) {
        const id = parentTag ? `${parentTag.id()}_${tag.id()}` : String(tag.id());
        const explicit = this.state[id];
        if (explicit && explicit.state) return explicit;
        return { state: this.defaultStateFor(tag, parentTag) };
    }

    /**
     * Count secondary-tag overrides under the given primary tag.
     * An override = explicit state in this.state whose key is `{primaryId}_{any}`
     * with a non-default ("inherit") state.
     */
    overridesCountFor(primaryTag) {
        const prefix = String(primaryTag.id()) + '_';
        let count = 0;
        for (const key of Object.keys(this.state)) {
            if (!key.startsWith(prefix)) continue;
            const v = this.state[key];
            if (!v || !v.state) continue;
            if (v.state === 'inherit') continue;
            if (v.state === 'groups' && (!v.groups || v.groups.length === 0)) continue;
            count++;
        }
        return count;
    }

    expandAll() {
        const roots = this.rootTags();
        for (const tag of roots) {
            if (this.childrenOf(tag).length > 0) {
                this.expanded[String(tag.id())] = true;
            }
        }
    }

    collapseAll() {
        this.expanded = {};
    }

    // ── Standalone secondary tags ────────────────────────────────────────
    // A discussion tagged with ONLY secondary tags (no primary) is resolved by
    // the backend via each secondary's own (bare) key — separate from the
    // `primary_secondary` overrides above. These rows let the admin set that
    // standalone state. Default is "Enabled" (matches the policy default), so
    // only "Disabled" gets persisted.

    // Standalone secondary tags default to Disabled, so the "exceptions" worth
    // badging are the ones explicitly Enabled.
    secondaryEnabledCount() {
        let n = 0;
        for (const tag of this.childrenOf()) {
            const e = this.state[String(tag.id())];
            if (e && e.state === STATE_ENABLED) n++;
        }
        return n;
    }

    setAllSecondary(state) {
        for (const tag of this.childrenOf()) {
            this.state[String(tag.id())] = { state };
        }
        this.persist();
        m.redraw();
    }

    view() {
        const label = this.attrs.label ? <label>{this.attrs.label}</label> : null;
        const help = this.attrs.help ? <p className="helpText">{this.attrs.help}</p> : null;
        const adminNote = (
            <p className="helpText TagPermissionTree-adminNote">
                <i className="fas fa-info-circle"></i>{' '}
                {app.translator.trans('tryhackx-topic-rating.admin.settings.tag_admin_note', {}, true)}
            </p>
        );

        if (!app.tagList) {
            return (
                <div className="Form-group TagPermissionTree">
                    {label}{help}
                    <p className="helpText">
                        {app.translator.trans('tryhackx-topic-rating.admin.settings.tags_unavailable', {}, true)}
                    </p>
                </div>
            );
        }

        if (!this.loaded) {
            return (
                <div className="Form-group TagPermissionTree">
                    {label}{help}
                    <LoadingIndicator size="small" display="inline" />
                </div>
            );
        }

        const roots = this.rootTags();
        const anyExpandable = roots.some((t) => this.childrenOf(t).length > 0);

        return (
            <div className="Form-group TagPermissionTree">
                {label}{help}{adminNote}

                {this.loadError && (
                    <p className="helpText TagPermissionTree-error">
                        Failed to load tags. See browser console.
                    </p>
                )}

                {anyExpandable && (
                    <div className="TagPermissionTree-toolbar">
                        <button
                            type="button"
                            className="Button Button--text TagPermissionTree-toolBtn"
                            onclick={() => { this.expandAll(); }}
                        >
                            <i className="fas fa-chevron-down"></i>{' '}
                            {app.translator.trans('tryhackx-topic-rating.admin.settings.tag_expand_all', {}, true)}
                        </button>
                        <button
                            type="button"
                            className="Button Button--text TagPermissionTree-toolBtn"
                            onclick={() => { this.collapseAll(); }}
                        >
                            <i className="fas fa-chevron-up"></i>{' '}
                            {app.translator.trans('tryhackx-topic-rating.admin.settings.tag_collapse_all', {}, true)}
                        </button>
                    </div>
                )}

                <div className="TagPermissionTree-list">
                    {roots.length === 0
                        ? (
                            <p className="helpText TagPermissionTree-empty">
                                {this.tags.length === 0
                                    ? app.translator.trans('tryhackx-topic-rating.admin.settings.tags_empty', {}, true)
                                    : app.translator.trans('tryhackx-topic-rating.admin.settings.tags_only_secondary', {}, true)}
                            </p>
                        )
                        : roots.map((tag) => this.renderRow(tag, 0, null))}
                </div>

                {roots.length > 0 && !anyExpandable && (
                    <p className="helpText TagPermissionTree-hierarchyHint">
                        <i className="fas fa-sitemap"></i>{' '}
                        {app.translator.trans('tryhackx-topic-rating.admin.settings.tag_hierarchy_hint', {}, true)}
                    </p>
                )}

                {this.childrenOf().length > 0 && this.renderSecondarySection()}
            </div>
        );
    }

    renderSecondarySection() {
        const secondaryTags = this.childrenOf();
        const enabledCount = this.secondaryEnabledCount();

        return (
            <div className="TagPermissionSecondaryTree">
                <div
                    className="TagPermissionSecondaryTree-header"
                    onclick={() => { this.secondaryExpanded = !this.secondaryExpanded; }}
                >
                    <i className={'TagPermissionSecondaryTree-caret ' + (this.secondaryExpanded ? 'fas fa-caret-down' : 'fas fa-caret-right')}></i>
                    <span className="TagPermissionSecondaryTree-title">
                        {app.translator.trans('tryhackx-topic-rating.admin.settings.secondary_solo_title', {}, true)}
                    </span>
                    {enabledCount > 0 && (
                        <span className="TagPermissionTree-overridesBadge" title={app.translator.trans('tryhackx-topic-rating.admin.settings.tag_overrides_count', { count: enabledCount }, true)}>
                            <i className="fas fa-check"></i> {enabledCount}
                        </span>
                    )}
                </div>

                <p className="helpText TagPermissionSecondaryTree-help">
                    {app.translator.trans('tryhackx-topic-rating.admin.settings.secondary_solo_help', {}, true)}
                </p>

                {this.secondaryExpanded && (
                    <div className="TagPermissionTree-toolbar">
                        <button
                            type="button"
                            className="Button Button--text TagPermissionTree-toolBtn"
                            onclick={() => this.setAllSecondary(STATE_ENABLED)}
                        >
                            <i className="fas fa-check"></i>{' '}
                            {app.translator.trans('tryhackx-topic-rating.admin.settings.secondary_enable_all', {}, true)}
                        </button>
                        <button
                            type="button"
                            className="Button Button--text TagPermissionTree-toolBtn"
                            onclick={() => this.setAllSecondary(STATE_DISABLED)}
                        >
                            <i className="fas fa-ban"></i>{' '}
                            {app.translator.trans('tryhackx-topic-rating.admin.settings.secondary_disable_all', {}, true)}
                        </button>
                    </div>
                )}

                {this.secondaryExpanded && (
                    <div className="TagPermissionSecondaryTree-list">
                        {secondaryTags.map((tag) => this.renderSecondaryRow(tag))}
                    </div>
                )}
            </div>
        );
    }

    renderSecondaryRow(tag) {
        const id = String(tag.id());
        // Default is Disabled for standalone secondary tags; only an explicit
        // "Enabled" stored entry flips it on.
        const stored = this.state[id];
        const state = (stored && stored.state === STATE_ENABLED) ? STATE_ENABLED : STATE_DISABLED;

        const stateOptions = {
            [STATE_ENABLED]:  app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_enabled', {}, true),
            [STATE_DISABLED]: app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_disabled', {}, true),
        };

        return (
            <div key={'sec-' + id} className={'TagPermissionTree-row TagPermissionTree-row--root TagPermissionTree-row--state-' + state + ' TagPermissionSecondaryTree-row'}>
                <div className="TagPermissionTree-rowMain">
                    <span className="TagPermissionTree-togglePlaceholder"></span>
                    <span className="TagPermissionTree-label">
                        {tag.color() && <span className="TagPermissionTree-swatch" style={{ backgroundColor: tag.color() }}></span>}
                        {tag.icon() && <i className={tag.icon() + ' TagPermissionTree-icon'}></i>}
                        <span className="TagPermissionTree-name" title={tag.name()}>{tag.name()}</span>
                    </span>
                    <span className="TagPermissionTree-stateWrap">
                        {Select.component({
                            value: state,
                            options: stateOptions,
                            onchange: (val) => this.updateEntry(id, { state: val === STATE_DISABLED ? STATE_DISABLED : STATE_ENABLED }),
                        })}
                    </span>
                </div>
            </div>
        );
    }

    renderRow(tag, depth, parentTag) {
        const id = parentTag ? `${parentTag.id()}_${tag.id()}` : String(tag.id());
        const entry = this.getEntry(tag, parentTag);
        const children = depth === 0 ? this.childrenOf(tag) : [];
        const hasChildren = children.length > 0;
        const isRoot = depth === 0;

        const canExpand = hasChildren;
        const isExpanded = canExpand && !!this.expanded[id];

        const stateOptions = isRoot
            ? {
                [STATE_ENABLED]:  app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_enabled', {}, true),
                [STATE_DISABLED]: app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_disabled', {}, true),
                [STATE_GROUPS]:   app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_groups', {}, true),
            }
            : {
                [STATE_INHERIT]:  app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_inherit', {}, true),
                [STATE_ENABLED]:  app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_enabled', {}, true),
                [STATE_DISABLED]: app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_disabled', {}, true),
                [STATE_GROUPS]:   app.translator.trans('tryhackx-topic-rating.admin.settings.tag_state_groups', {}, true),
            };

        const onStateChange = (val) => {
            if (val === STATE_GROUPS) {
                this.updateEntry(id, { state: STATE_GROUPS, groups: entry.groups || [] });
            } else if (val === STATE_DISABLED) {
                this.updateEntry(id, { state: STATE_DISABLED });
            } else if (val === STATE_ENABLED) {
                this.updateEntry(id, { state: STATE_ENABLED });
            } else {
                this.updateEntry(id, { state: STATE_INHERIT });
            }
            if (hasChildren) {
                this.expanded[id] = true;
            }
        };

        let effectiveState = entry.state;
        if (!isRoot && entry.state === STATE_INHERIT && parentTag) {
            const parentEntry = this.getEntry(parentTag, null);
            if (parentEntry.state === STATE_DISABLED) {
                effectiveState = 'inherit-disabled';
            } else if (parentEntry.state === STATE_GROUPS) {
                effectiveState = 'inherit-groups';
            } else {
                effectiveState = 'inherit-enabled';
            }
        }

        const rowClass = [
            'TagPermissionTree-row',
            'TagPermissionTree-row--depth-' + depth,
            'TagPermissionTree-row--state-' + effectiveState,
            hasChildren ? 'TagPermissionTree-row--hasChildren' : '',
            isRoot ? 'TagPermissionTree-row--root' : 'TagPermissionTree-row--child',
        ].join(' ');

        return (
            <div key={'tag-' + id} className={rowClass}>
                <div
                    className={'TagPermissionTree-rowMain' + (canExpand ? ' TagPermissionTree-rowMain--expandable' : '')}
                    style={{ paddingLeft: (8 + depth * 20) + 'px' }}
                    onclick={(e) => {
                        if (e.target.closest('.TagPermissionTree-stateWrap') || e.target.closest('.Select')) {
                            return;
                        }
                        if (canExpand) {
                            e.stopPropagation();
                            this.expanded[id] = !this.expanded[id];
                            m.redraw();
                        }
                    }}
                >
                    {canExpand ? (
                        <button
                            type="button"
                            className="TagPermissionTree-toggle"
                            onclick={(e) => {
                                e.stopPropagation();
                                this.expanded[id] = !this.expanded[id];
                            }}
                            aria-expanded={isExpanded ? 'true' : 'false'}
                            title={isExpanded ? '−' : '+'}
                        >
                            <i className={isExpanded ? 'fas fa-caret-down' : 'fas fa-caret-right'}></i>
                        </button>
                    ) : (
                        <span className="TagPermissionTree-togglePlaceholder"></span>
                    )}

                    <span className="TagPermissionTree-label">
                        {tag.color() && <span className="TagPermissionTree-swatch" style={{ backgroundColor: tag.color() }}></span>}
                        {tag.icon() && <i className={tag.icon() + ' TagPermissionTree-icon'}></i>}
                        <span className="TagPermissionTree-name" title={tag.name()}>{tag.name()}</span>
                        {hasChildren && (
                            <span className="TagPermissionTree-childCount">({children.length})</span>
                        )}
                        {isRoot && hasChildren && (() => {
                            const n = this.overridesCountFor(tag);
                            if (n === 0) return null;
                            return (
                                <span className="TagPermissionTree-overridesBadge" title={app.translator.trans('tryhackx-topic-rating.admin.settings.tag_overrides_count', { count: n }, true)}>
                                    <i className="fas fa-pen"></i> {n}
                                </span>
                            );
                        })()}
                    </span>

                    <span className="TagPermissionTree-stateWrap">
                        {Select.component({
                            value: entry.state,
                            options: stateOptions,
                            onchange: onStateChange,
                        })}
                    </span>
                </div>

                {entry.state === STATE_GROUPS && this.renderGroupPicker(id, entry, depth)}

                {isExpanded && (
                    <div className="TagPermissionTree-children">
                        {children.map((child) => this.renderRow(child, depth + 1, tag))}
                    </div>
                )}
            </div>
        );
    }

    renderGroupPicker(tagId, entry, depth) {
        const selected = (entry.groups || []).map(String);

        return (
            <div
                className="TagPermissionTree-groups"
                style={{ paddingLeft: (32 + depth * 20) + 'px' }}
            >
                {this.groups.length === 0
                    ? <span className="helpText">{app.translator.trans('tryhackx-topic-rating.admin.settings.tag_no_groups', {}, true)}</span>
                    : this.groups.map((g) => {
                        const gid = String(g.id());
                        const isSelected = selected.includes(gid);
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
                                className={'TagPermissionTree-groupPill' + (isSelected ? ' TagPermissionTree-groupPill--selected' : '')}
                                onclick={() => {
                                    const next = isSelected
                                        ? selected.filter((x) => x !== gid)
                                        : selected.concat(gid);
                                    this.updateEntry(tagId, { state: STATE_GROUPS, groups: next });
                                }}
                                style={pillStyle}
                            >
                                {g.icon() && <i className={g.icon() + ' TagPermissionTree-groupPillIcon'}></i>}
                                <span>{g.namePlural() || g.nameSingular()}</span>
                                {isSelected && <i className="fas fa-check TagPermissionTree-groupCheck"></i>}
                            </button>
                        );
                    })
                }
            </div>
        );
    }
}
