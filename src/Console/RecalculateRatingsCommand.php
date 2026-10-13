<?php

namespace TryHackX\TopicRating\Console;

use Flarum\Console\AbstractCommand;
use TryHackX\TopicRating\RatingRecalculator;

/**
 * `php flarum topic-rating:recalculate` — rebuilds every discussion's cached
 * rating aggregates from the stored ratings. Normally never needed (writes keep
 * them in sync); useful after importing ratings or editing the database by hand.
 */
class RecalculateRatingsCommand extends AbstractCommand
{
    public function __construct(
        protected RatingRecalculator $recalculator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('topic-rating:recalculate')
            ->setDescription('Rebuild the cached rating counts/averages of every discussion from the stored ratings.');
    }

    protected function fire(): int
    {
        $this->info('Recalculating topic ratings…');
        $this->recalculator->recalculateAll();
        $this->info('Done.');

        return 0;
    }
}
