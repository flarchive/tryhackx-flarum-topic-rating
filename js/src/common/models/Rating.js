import Model from 'flarum/common/Model';

export default class Rating extends Model {}

Object.assign(Rating.prototype, {
    rating: Model.attribute('rating'),
    createdAt: Model.attribute('createdAt', Model.transformDate),
    updatedAt: Model.attribute('updatedAt', Model.transformDate),
    user: Model.hasOne('user'),
});
