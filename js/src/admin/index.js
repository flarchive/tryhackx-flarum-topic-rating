import Extend from 'flarum/common/extenders';
import { extend } from 'flarum/common/extend';
import app from 'flarum/admin/app';
import FormGroup from 'flarum/common/components/FormGroup';
import ResetExtensionSettingsModal from 'flarum/admin/components/ResetExtensionSettingsModal';
import SupportModal from './components/SupportModal';
import TagPermissionTree from './components/TagPermissionTree';
import BypassGroupPicker from './components/BypassGroupPicker';
import AvatarSettings from './components/AvatarSettings';
import RatingListDisplaySettings from './components/RatingListDisplaySettings';

// Give the Cancel button in core's "Reset extension settings" modal Flarum's
// standard `Button--inverted` styling (core renders it as a plain borderless
// button). The modal is registered in the admin component registry, so we
// extend it directly instead of running a whole-document MutationObserver for
// the entire admin session. Guarded in case a future core build drops it.
app.initializers.add('tryhackx-topic-rating-cancel-inverted', () => {
    if (!ResetExtensionSettingsModal || !ResetExtensionSettingsModal.prototype) return;

    const invertCancel = function () {
        const root = this.element;
        if (!root) return;
        const cancel = root.querySelector('.Form-controls .Button:not(.Button--danger):not(.Button--primary)');
        if (cancel) cancel.classList.add('Button--inverted');
    };

    extend(ResetExtensionSettingsModal.prototype, 'oncreate', invertCancel);
    extend(ResetExtensionSettingsModal.prototype, 'onupdate', invertCancel);
});

app.initializers.add('tryhackx-topic-rating-support', () => {
    app.registry.for('tryhackx-topic-rating').registerSetting(function () {
        return m('div', { className: 'TopicRating-support' }, [
            m('button', {
                className: 'Button',
                onclick: () => app.modal.show(SupportModal),
            }, [
                m('i', { className: 'fas fa-heart Button-icon icon' }),
                app.translator.trans('tryhackx-topic-rating.admin.support.button'),
            ]),
        ]);
    });
});

// Register custom FormGroup field type used by the tag-config setting below.
// Harmless when Tags isn't installed (just nothing references this type).
app.initializers.add('tryhackx-topic-rating-custom-fields', () => {
    extend(FormGroup.prototype, 'customFieldComponents', function (items) {
        items.add('tryhackx-topic-rating.tag-permission-tree', (attrs) => {
            return m(TagPermissionTree, Object.assign({}, attrs, { settingValue: attrs.bidi }));
        });
        items.add('tryhackx-topic-rating.bypass-group-picker', (attrs) => {
            return m(BypassGroupPicker, Object.assign({}, attrs, { settingValue: attrs.bidi }));
        });
    });
});

const tagsOn = !!(typeof flarum !== 'undefined' && flarum.extensions && flarum.extensions['flarum-tags']);

const adminExtender = new Extend.Admin()
    .setting(() => ({
        setting: 'tryhackx-topic-rating.enabled',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.enabled_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.enabled_help', {}, true),
        type: 'boolean',
    }))
    .setting(() => ({
        setting: 'tryhackx-topic-rating.show_on_list',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.show_on_list_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.show_on_list_help', {}, true),
        type: 'boolean',
    }))
    .setting(() => ({
        setting: 'tryhackx-topic-rating.rate_on_list',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.rate_on_list_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.rate_on_list_help', {}, true),
        type: 'boolean',
    }))
    .setting(() => ({
        setting: 'tryhackx-topic-rating.hide_empty_for_nonvoters',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.hide_empty_for_nonvoters_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.hide_empty_for_nonvoters_help', {}, true),
        type: 'boolean',
    }))
    .setting(() => ({
        setting: 'tryhackx-topic-rating.allow_unactivated',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.allow_unactivated_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.allow_unactivated_help', {}, true),
        type: 'boolean',
    }))
    .setting(() => ({
        setting: 'tryhackx-topic-rating.show_moderation_controls',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.show_moderation_controls_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.show_moderation_controls_help', {}, true),
        type: 'boolean',
    }))
    .setting(() => ({
        setting: 'tryhackx-topic-rating.realtime_enabled',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.realtime_enabled_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.realtime_enabled_help', {}, true),
        type: 'boolean',
    }))
    .setting(() => ({
        setting: 'tryhackx-topic-rating.poll_interval',
        label: app.translator.trans('tryhackx-topic-rating.admin.settings.poll_interval_label', {}, true),
        help: app.translator.trans('tryhackx-topic-rating.admin.settings.poll_interval_help', {}, true),
        type: 'number',
        min: 5,
        max: 300,
    }))
    // Per-device rating display style + cascading single-star options.
    .customSetting(() => m(RatingListDisplaySettings))
    // Shared avatar section (also present in Thumb Sliders; same setting keys).
    .customSetting(() => m(AvatarSettings));

if (tagsOn) {
    adminExtender
        .setting(() => ({
            setting: 'tryhackx-topic-rating.tag_config',
            label: app.translator.trans('tryhackx-topic-rating.admin.settings.tag_config_label', {}, true),
            help: app.translator.trans('tryhackx-topic-rating.admin.settings.tag_config_help', {}, true),
            type: 'tryhackx-topic-rating.tag-permission-tree',
        }))
        .setting(() => ({
            setting: 'tryhackx-topic-rating.untagged_enabled',
            label: app.translator.trans('tryhackx-topic-rating.admin.settings.untagged_enabled_label', {}, true),
            help: app.translator.trans('tryhackx-topic-rating.admin.settings.untagged_enabled_help', {}, true),
            type: 'boolean',
        }))
        .setting(() => ({
            setting: 'tryhackx-topic-rating.display_when_restricted',
            label: app.translator.trans('tryhackx-topic-rating.admin.settings.display_when_restricted_label', {}, true),
            help: app.translator.trans('tryhackx-topic-rating.admin.settings.display_when_restricted_help', {}, true),
            type: 'select',
            options: {
                readonly: app.translator.trans('tryhackx-topic-rating.admin.settings.display_when_restricted_readonly', {}, true),
                hidden: app.translator.trans('tryhackx-topic-rating.admin.settings.display_when_restricted_hidden', {}, true),
                message: app.translator.trans('tryhackx-topic-rating.admin.settings.display_when_restricted_message', {}, true),
            },
        }))
        .setting(() => ({
            setting: 'tryhackx-topic-rating.hide_disabled_ratings',
            label: app.translator.trans('tryhackx-topic-rating.admin.settings.hide_disabled_ratings_label', {}, true),
            help: app.translator.trans('tryhackx-topic-rating.admin.settings.hide_disabled_ratings_help', {}, true),
            type: 'boolean',
        }))
        .setting(() => ({
            setting: 'tryhackx-topic-rating.bypass_groups',
            label: app.translator.trans('tryhackx-topic-rating.admin.settings.bypass_groups_label', {}, true),
            help: app.translator.trans('tryhackx-topic-rating.admin.settings.bypass_groups_help', {}, true),
            type: 'tryhackx-topic-rating.bypass-group-picker',
        }));
}

adminExtender
    .permission(() => ({
        icon: 'fas fa-star',
        label: app.translator.trans('tryhackx-topic-rating.admin.permissions.rate_label', {}, true),
        permission: 'discussion.rate',
    }), 'reply', 65)
    .permission(() => ({
        icon: 'fas fa-star-half-alt',
        label: app.translator.trans('tryhackx-topic-rating.admin.permissions.toggle_label', {}, true),
        permission: 'discussion.rate.toggle',
    }), 'moderate', 65)
    .permission(() => ({
        icon: 'fas fa-eraser',
        label: app.translator.trans('tryhackx-topic-rating.admin.permissions.reset_label', {}, true),
        permission: 'discussion.rate.reset',
    }), 'moderate', 64);

export default [adminExtender];
