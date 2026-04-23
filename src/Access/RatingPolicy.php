<?php

namespace TryHackX\TopicRating\Access;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class RatingPolicy extends AbstractPolicy
{
    protected $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function rate(User $actor, Discussion $discussion)
    {
        if ($discussion->rating_disabled) {
            return $this->deny();
        }

        if ($actor->hasPermission('discussion.rate')) {
            return $this->allow();
        }

        if ($actor->id && !$actor->is_email_confirmed) {
            $allowUnactivated = (bool) $this->settings->get('tryhackx-topic-rating.allow_unactivated', false);
            if ($allowUnactivated) {
                return $this->allow();
            }
        }

        return $this->deny();
    }
}
