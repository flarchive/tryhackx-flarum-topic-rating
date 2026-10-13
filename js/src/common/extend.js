import Model from 'flarum/common/Model';
import Discussion from 'flarum/common/models/Discussion';

Discussion.prototype.ratingCount = Model.attribute('ratingCount');
Discussion.prototype.ratingAverage = Model.attribute('ratingAverage');
Discussion.prototype.lastRatedAt = Model.attribute('lastRatedAt', Model.transformDate);
Discussion.prototype.ratingDisabled = Model.attribute('ratingDisabled');
Discussion.prototype.canRate = Model.attribute('canRate');
Discussion.prototype.canRateRequiresActivation = Model.attribute('canRateRequiresActivation');
Discussion.prototype.ratingDisplayMode = Model.attribute('ratingDisplayMode');
Discussion.prototype.canToggleRating = Model.attribute('canToggleRating');
Discussion.prototype.canResetRatings = Model.attribute('canResetRatings');
Discussion.prototype.userRating = Model.attribute('userRating');
// Author mode ("review mode"): which mode applies to the discussion, the author's
// own rating (1–10, only in the modes that show it separately) and whether the
// current viewer started the discussion.
Discussion.prototype.ratingAuthorMode = Model.attribute('ratingAuthorMode');
Discussion.prototype.authorRating = Model.attribute('authorRating');
Discussion.prototype.ratingViewerIsAuthor = Model.attribute('ratingViewerIsAuthor');
// Every stored rating (moderators only) — what "Reset All Ratings" deletes.
Discussion.prototype.ratingTotalCount = Model.attribute('ratingTotalCount');
