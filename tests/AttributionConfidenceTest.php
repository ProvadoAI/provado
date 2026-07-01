<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use DateTimeImmutable;
use Mquevedob\Provado\Diagnosis\AttributionConfidence;
use Mquevedob\Provado\Diagnosis\OnsetEstimate;
use PHPUnit\Framework\TestCase;

class AttributionConfidenceTest extends TestCase
{
    public function test_a_symptom_starting_near_after_the_cause_onset_scores_high(): void
    {
        // Symptom onset 5 minutes after the cause onset: plausible propagation.
        $confidence = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('11:05'));

        $this->assertSame(0.92, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_HIGH, $confidence->band);
        $this->assertSame(300, $confidence->onsetDeltaSeconds);
        $this->assertFalse($confidence->isLikelyCoincidence());
    }

    public function test_simultaneous_onsets_score_a_full_one(): void
    {
        $confidence = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('11:00'));

        $this->assertSame(1.0, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_HIGH, $confidence->band);
    }

    public function test_a_symptom_already_bad_long_before_the_cause_scores_low(): void
    {
        // Symptom onset 2 hours before the cause onset — it cannot have been
        // caused by it: likely coincidence, demoted.
        $confidence = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('09:00'));

        $this->assertSame(0.0, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_LOW, $confidence->band);
        $this->assertSame(-7200, $confidence->onsetDeltaSeconds);
        $this->assertTrue($confidence->isLikelyCoincidence());
    }

    public function test_a_symptom_starting_half_a_horizon_after_reads_medium(): void
    {
        $confidence = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('11:30'));

        $this->assertSame(0.5, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_MEDIUM, $confidence->band);
    }

    public function test_a_symptom_starting_beyond_the_horizon_after_reads_low(): void
    {
        $confidence = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('13:00'));

        $this->assertSame(0.0, $confidence->score);
        $this->assertTrue($confidence->isLikelyCoincidence());
    }

    public function test_decay_is_asymmetric_a_preceding_onset_decays_faster(): void
    {
        // Same 10-minute gap: after the cause it is propagation (long horizon),
        // before the cause it is causally implausible beyond jitter (short one).
        $after = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('11:10'));
        $before = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('10:50'));

        $this->assertSame(0.83, $after->score);
        $this->assertSame(0.33, $before->score);
    }

    public function test_a_small_preceding_gap_is_polling_jitter_not_coincidence(): void
    {
        // 5 minutes before the cause onset is within the jitter tolerance —
        // different shippers poll at different moments.
        $confidence = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('10:55'));

        $this->assertSame(0.67, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_HIGH, $confidence->band);
    }

    public function test_cold_start_on_both_sides_reads_unknown_and_never_demotes(): void
    {
        // Both series were bad from their first snapshot: neither onset is real,
        // so the score is unknowable — attributed, flagged, not demoted.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00', censored: true),
            $this->onset('11:00', censored: true),
        );

        $this->assertNull($confidence->score);
        $this->assertSame(AttributionConfidence::BAND_UNKNOWN, $confidence->band);
        $this->assertTrue($confidence->censored);
        $this->assertFalse($confidence->isLikelyCoincidence());
    }

    public function test_a_censored_symptom_onset_still_demotes_when_it_predates_the_cause(): void
    {
        // The symptom was bad from its first snapshot, an hour before the cause
        // onset. Its true onset can only be even earlier — the demotion stands.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('10:00', censored: true),
        );

        $this->assertSame(0.0, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_LOW, $confidence->band);
        $this->assertTrue($confidence->censored);
        $this->assertTrue($confidence->isLikelyCoincidence());
    }

    public function test_a_censored_cause_onset_still_demotes_a_symptom_long_after_it(): void
    {
        // The cause was bad from its first snapshot; the symptom started 2 hours
        // after that. The true cause onset can only be earlier, widening the gap.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00', censored: true),
            $this->onset('13:00'),
        );

        $this->assertSame(0.0, $confidence->score);
        $this->assertTrue($confidence->isLikelyCoincidence());
    }

    public function test_a_censored_symptom_onset_after_the_cause_cannot_demote(): void
    {
        // Observed 2 hours after the cause (would read low), but the symptom was
        // bad from its first snapshot — its true onset could sit right at the
        // cause onset. Censoring could overturn the demotion → unknown.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('13:00', censored: true),
        );

        $this->assertNull($confidence->score);
        $this->assertSame(AttributionConfidence::BAND_UNKNOWN, $confidence->band);
        $this->assertFalse($confidence->isLikelyCoincidence());
    }

    public function test_a_censored_cause_onset_cannot_demote_a_preceding_symptom(): void
    {
        // Symptom observed an hour before the cause onset (would read low), but
        // the cause was bad from its first snapshot — its true onset could
        // predate the symptom. Censoring could overturn the demotion → unknown.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00', censored: true),
            $this->onset('10:00'),
        );

        $this->assertNull($confidence->score);
        $this->assertSame(AttributionConfidence::BAND_UNKNOWN, $confidence->band);
    }

    public function test_a_censored_near_onset_reads_unknown_not_high(): void
    {
        // Near-onset would read high, but the symptom's onset is censored — the
        // true onset may be long before the cause. Never promote on guesswork.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('11:05', censored: true),
        );

        $this->assertNull($confidence->score);
        $this->assertSame(AttributionConfidence::BAND_UNKNOWN, $confidence->band);
    }

    public function test_a_config_change_stamping_the_cause_onset_adds_a_bonus(): void
    {
        // Change 10 minutes before the cause onset: corroborates it.
        $plain = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('11:30'));
        $stamped = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('11:30'),
            configChangedAt: $this->at('10:50'),
        );

        $this->assertSame(0.5, $plain->score);
        $this->assertSame(0.65, $stamped->score);
        $this->assertSame(AttributionConfidence::BAND_HIGH, $stamped->band);
        $this->assertTrue($stamped->corroboratedByConfigChange);
    }

    public function test_the_config_change_bonus_is_capped_at_one(): void
    {
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('11:00'),
            configChangedAt: $this->at('10:50'),
        );

        $this->assertSame(1.0, $confidence->score);
    }

    public function test_a_config_change_older_than_the_corroboration_window_does_not_corroborate(): void
    {
        // Change 2 hours before the cause onset: unrelated, no bonus.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('11:30'),
            configChangedAt: $this->at('09:00'),
        );

        $this->assertSame(0.5, $confidence->score);
        $this->assertFalse($confidence->corroboratedByConfigChange);
    }

    public function test_a_config_change_after_the_cause_onset_does_not_corroborate(): void
    {
        // Snapshots only observe an onset late, never early — a change after the
        // observed onset cannot have caused it.
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('11:30'),
            configChangedAt: $this->at('11:10'),
        );

        $this->assertSame(0.5, $confidence->score);
        $this->assertFalse($confidence->corroboratedByConfigChange);
    }

    public function test_the_bonus_never_boosts_a_symptom_that_predates_the_cause(): void
    {
        // Symptom onset 700s before the cause onset reads low; a change stamping
        // the cause onset argues against attributing it, not for it — the bonus
        // must not rescue it into the headline.
        $plain = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('10:48:20'));
        $stamped = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('10:48:20'),
            configChangedAt: $this->at('10:55'),
        );

        $this->assertSame(AttributionConfidence::BAND_LOW, $plain->band);
        $this->assertSame($plain->score, $stamped->score);
        $this->assertSame(AttributionConfidence::BAND_LOW, $stamped->band);
        $this->assertTrue($stamped->corroboratedByConfigChange);
        $this->assertTrue($stamped->isLikelyCoincidence());
    }

    public function test_the_bonus_cannot_flip_a_censoring_proof_demotion_into_unknown(): void
    {
        // Censored symptom predating the cause: the demotion survives censoring,
        // and a corroborating change must not push the score out of the low band
        // (that would turn "demoted" into "unknown" — losing information by
        // adding evidence).
        $confidence = AttributionConfidence::fromOnsets(
            $this->onset('11:00'),
            $this->onset('10:46:40', censored: true), // delta -800 → raw 0.11
            configChangedAt: $this->at('10:55'),
        );

        $this->assertSame(0.11, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_LOW, $confidence->band);
        $this->assertTrue($confidence->isLikelyCoincidence());
    }

    public function test_the_published_score_and_band_agree_at_the_band_boundary(): void
    {
        // delta 1450s → raw 0.5972, which rounds to 0.60: the published pair must
        // be consistent (0.60 is high), not "score 0.60, band medium".
        $confidence = AttributionConfidence::fromOnsets($this->onset('11:00'), $this->onset('11:24:10'));

        $this->assertSame(0.6, $confidence->score);
        $this->assertSame(AttributionConfidence::BAND_HIGH, $confidence->band);
    }

    private function onset(string $time, bool $censored = false): OnsetEstimate
    {
        return new OnsetEstimate($this->at($time), $censored);
    }

    private function at(string $time): DateTimeImmutable
    {
        $hhmmss = strlen($time) === 5 ? $time.':00' : $time;

        return new DateTimeImmutable('2026-06-30T'.$hhmmss.'+00:00');
    }
}
