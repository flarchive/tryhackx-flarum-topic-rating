import app from 'flarum/admin/app';
import SupportModal from './components/SupportModal';

app.initializers.add('tryhackx-topic-rating-support', () => {
    app.extensionData.for('tryhackx-topic-rating').registerSetting(function () {
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

app.initializers.add('tryhackx-topic-rating', () => {
    app.extensionData
        .for('tryhackx-topic-rating')
        .registerSetting({
            setting: 'tryhackx-topic-rating.enabled',
            label: app.translator.trans('tryhackx-topic-rating.admin.settings.enabled_label'),
            help: app.translator.trans('tryhackx-topic-rating.admin.settings.enabled_help'),
            type: 'boolean',
        })
        .registerSetting({
            setting: 'tryhackx-topic-rating.allow_unactivated',
            label: app.translator.trans('tryhackx-topic-rating.admin.settings.allow_unactivated_label'),
            help: app.translator.trans('tryhackx-topic-rating.admin.settings.allow_unactivated_help'),
            type: 'boolean',
        })
        .registerPermission(
            {
                icon: 'fas fa-star',
                label: app.translator.trans('tryhackx-topic-rating.admin.permissions.rate_label'),
                permission: 'discussion.rate',
            },
            'reply',
            65
        )
        .registerPermission(
            {
                icon: 'fas fa-star-half-alt',
                label: app.translator.trans('tryhackx-topic-rating.admin.permissions.toggle_label'),
                permission: 'discussion.rate.toggle',
            },
            'moderate',
            65
        )
        .registerPermission(
            {
                icon: 'fas fa-eraser',
                label: app.translator.trans('tryhackx-topic-rating.admin.permissions.reset_label'),
                permission: 'discussion.rate.reset',
            },
            'moderate',
            64
        );
});
