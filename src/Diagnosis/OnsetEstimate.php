<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Diagnosis;

use DateTimeImmutable;

/**
 * The estimated onset of a current bad run: the timestamp of the earliest
 * snapshot in the unbroken run of bad snapshots ending at the latest one.
 *
 * The estimate is **censored** when that run reaches the first snapshot of the
 * series — the entity was never observed healthy, so the true onset may be
 * arbitrarily earlier than what we saw (the observed timestamp is only an upper
 * bound). That covers two situations, not just cold start: a source that only
 * began shipping, and an incident *older than the analysis window* (the series
 * starts at the window edge). Attribution scoring treats a censored onset
 * asymmetrically: it can confirm that a symptom predates a cause, but never
 * that it coincides with it.
 */
final readonly class OnsetEstimate
{
    public function __construct(
        public DateTimeImmutable $timestamp,
        public bool $censored,
    ) {
    }
}
