<?php

namespace TryHackX\TopicRating\Job;

use Flarum\Queue\AbstractJob;
use TryHackX\TopicRating\RatingRecalculator;

/**
 * Re-derives every discussion's displayed/sorted score after the author mode or
 * its tag scope changed. Queued so a forum running a real queue worker does the
 * bulk UPDATE in the background; with Flarum's default `sync` queue it simply
 * runs at the end of the settings request (still cheap — same-row arithmetic in
 * chunks, see RatingRecalculator::refreshEffective).
 */
class RefreshEffectiveScores extends AbstractJob
{
    public function handle(RatingRecalculator $recalculator): void
    {
        $recalculator->refreshEffective();
    }
}
