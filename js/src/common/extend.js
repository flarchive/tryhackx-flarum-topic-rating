import Model from 'flarum/common/Model';
import Discussion from 'flarum/common/models/Discussion';

Discussion.prototype.ratingCount = Model.attribute('ratingCount');
Discussion.prototype.ratingAverage = Model.attribute('ratingAverage');
Discussion.prototype.lastRatedAt = Model.attribute('lastRatedAt', Model.transformDate);
Discussion.prototype.ratingDisabled = Model.attribute('ratingDisabled');
Discussion.prototype.canRate = Model.attribute('canRate');
Discussion.prototype.canRateRequiresActivation = Model.attribute('canRateRequiresActivation');
Discussion.prototype.canToggleRating = Model.attribute('canToggleRating');
Discussion.prototype.canResetRatings = Model.attribute('canResetRatings');
Discussion.prototype.userRating = Model.attribute('userRating');
