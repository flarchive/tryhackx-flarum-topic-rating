import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import app from 'flarum/forum/app';

export default class ResetRatingsModal extends Modal {
    oninit(vnode) {
        super.oninit(vnode);
        this.loading = false;
    }

    className() {
        return 'ResetRatingsModal Modal--small';
    }

    title() {
        return app.translator.trans('tryhackx-topic-rating.forum.reset_modal.title');
    }

    content() {
        const discussion = this.attrs.discussion;
        const count = discussion.ratingCount() || 0;

        return (
            <div className="Modal-body">
                <div className="ResetRatingsModal-content">
                    <div className="ResetRatingsModal-icon">
                        <i className="fas fa-exclamation-triangle"></i>
                    </div>
                    <p className="ResetRatingsModal-message">
                        {app.translator.trans('tryhackx-topic-rating.forum.reset_modal.message', { count })}
                    </p>
                    <div className="ResetRatingsModal-buttons">
                        <Button
                            className="Button"
                            onclick={() => this.hide()}
                            disabled={this.loading}
                        >
                            {app.translator.trans('tryhackx-topic-rating.forum.reset_modal.cancel')}
                        </Button>
                        <Button
                            className="Button Button--danger"
                            onclick={() => this.confirm()}
                            loading={this.loading}
                            disabled={this.loading}
                        >
                            {app.translator.trans('tryhackx-topic-rating.forum.reset_modal.confirm')}
                        </Button>
                    </div>
                </div>
            </div>
        );
    }

    confirm() {
        const discussion = this.attrs.discussion;
        this.loading = true;

        app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/discussions/' + discussion.id() + '/reset-ratings',
        }).then(() => {
            discussion.pushData({
                attributes: {
                    ratingCount: 0,
                    ratingAverage: 0,
                    lastRatedAt: null,
                    userRating: null,
                },
            });
            this.hide();
            m.redraw();
        }).catch(() => {
            this.loading = false;
            m.redraw();
        });
    }
}
