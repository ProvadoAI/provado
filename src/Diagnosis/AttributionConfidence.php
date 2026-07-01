<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Diagnosis;

use DateTimeImmutable;

/**
 * The confidence that a downstream symptom is attributable to an upstream cause,
 * scored from the temporal proximity of their onsets (v0.9.0 Phase 1 —
 * proximity-as-weight, see memory `proximity-as-weight-not-join`).
 *
 * This is a **weight applied after grouping**, never a join criterion: it ranks
 * the symptoms an entity-based correlation group already holds, and must not be
 * able to change which signals are grouped. Grouping stays binary; attribution
 * gains a confidence.
 *
 * The score is a linear decay of the onset gap, asymmetric around zero:
 *
 * - A symptom whose onset follows the cause onset is plausible propagation and
 *   decays over PROXIMITY_HORIZON_SECONDS.
 * - A symptom whose onset *precedes* the cause onset is causally implausible
 *   beyond polling jitter, so it decays 4× faster (over
 *   ONSET_JITTER_TOLERANCE_SECONDS) — "already bad long before" is the strongest
 *   coincidence evidence.
 *
 * Dwell participates as the *input* — onsets come from the same bad-run walk that
 * produces dwell — not as a second multiplier, so it is never double-counted. The
 * learned baseline stays the trigger that decides whether a state is bad at all;
 * the weight only ranks what the trigger already declared symptomatic.
 *
 * Censoring (see OnsetEstimate): a censored onset shifts the *true* onset gap in
 * one known direction — earlier symptom onset shifts it down, earlier cause onset
 * shifts it up. A censored estimate is therefore trusted only when the shift
 * cannot overturn the verdict: it may confirm a demotion (the symptom predates
 * the cause no matter how much earlier the truth is), never a promotion. Any
 * score that censoring could raise reads as `unknown` — attributed, flagged, and
 * never demoted on guesswork. This covers the cold start AND the long-running
 * incident: an incident older than the analysis window censors both onsets at
 * the window edge, so ranking long-dwell incidents needs a window that predates
 * them (the diagnose default of 24h comfortably covers the 1h horizon).
 *
 * A change-stamp at or shortly before the cause onset (the `config_change`
 * signal) corroborates that something really changed then and adds a small bonus
 * — but only to plausible propagation (symptom onset at or after the cause): for
 * a symptom already bad *before* the change, the stamp is evidence against
 * attribution, never for it. The nearness judgment lives here, against the cause
 * onset, so a caller cannot accidentally wire in a "recent relative to now"
 * recency test instead.
 *
 * The thresholds are named constants for now; the v0.9.0 Phase 3 config surface
 * exposes them.
 */
final readonly class AttributionConfidence
{
    /**
     * How long after the cause onset a symptom onset still (linearly) counts as
     * plausible propagation.
     */
    public const PROXIMITY_HORIZON_SECONDS = 3600;

    /**
     * Grace for a symptom onset observed *before* the cause onset — different
     * shippers poll at different moments, so small negative gaps are jitter, not
     * causality violations. Decays 4× faster than the forward horizon.
     */
    public const ONSET_JITTER_TOLERANCE_SECONDS = 900;

    /**
     * How long before the cause onset a config change still corroborates it.
     * Intentionally a separate knob from CronHealthPattern's recent-config
     * evidence window (same value today): that one measures change age against
     * "now" for the evidence field, this one against the cause onset for the
     * score. The Phase 3 config surface exposes them independently.
     */
    public const CONFIG_CHANGE_CORROBORATION_SECONDS = 3600;

    public const BAND_HIGH = 'high';

    public const BAND_MEDIUM = 'medium';

    public const BAND_LOW = 'low';

    public const BAND_UNKNOWN = 'unknown';

    /** Corroboration bonus when a config change stamps the cause onset. */
    private const CONFIG_CHANGE_BONUS = 0.15;

    /** Scores at or above this read as high-confidence attribution. */
    private const HIGH_FLOOR = 0.6;

    /** Scores below this read as likely coincidence and are demoted. */
    private const LOW_CEILING = 0.25;

    private function __construct(
        public ?float $score,
        public string $band,
        public int $onsetDeltaSeconds,
        public bool $censored,
        public bool $corroboratedByConfigChange,
    ) {
    }

    public static function fromOnsets(
        OnsetEstimate $cause,
        OnsetEstimate $symptom,
        ?DateTimeImmutable $configChangedAt = null,
    ): self {
        $delta = $symptom->timestamp->getTimestamp() - $cause->timestamp->getTimestamp();
        $corroborated = self::corroboratesCauseOnset($cause, $configChangedAt);
        $score = self::proximityScore($delta);

        // The bonus only strengthens plausible propagation (delta >= 0): a change
        // stamping the cause onset argues *against* attributing a symptom that was
        // already bad before the change.
        if ($corroborated && $delta >= 0) {
            $score = min(1.0, $score + self::CONFIG_CHANGE_BONUS);
        }

        // Round once, up front: the band, the demotion check and the published
        // score must all read the same value, or a boundary score publishes a
        // self-contradictory pair (e.g. score 0.60 with band medium).
        $score = round($score, 2);

        $censored = $cause->censored || $symptom->censored;

        if ($censored && ! self::demotionSurvivesCensoring($score, $delta, $cause->censored, $symptom->censored)) {
            return new self(null, self::BAND_UNKNOWN, $delta, true, $corroborated);
        }

        return new self($score, self::bandFor($score), $delta, $censored, $corroborated);
    }

    /**
     * Whether this symptom should leave the headline attribution and be called
     * out as likely coincidence instead. Unknown never demotes.
     */
    public function isLikelyCoincidence(): bool
    {
        return $this->band === self::BAND_LOW;
    }

    /**
     * A config change corroborates the cause when it lands at or up to the
     * corroboration window *before* the cause onset. A change after the onset
     * cannot have caused it (snapshots only ever observe the onset late, never
     * early), and an older change is unrelated.
     */
    private static function corroboratesCauseOnset(OnsetEstimate $cause, ?DateTimeImmutable $configChangedAt): bool
    {
        if ($configChangedAt === null) {
            return false;
        }

        $gap = $cause->timestamp->getTimestamp() - $configChangedAt->getTimestamp();

        return $gap >= 0 && $gap <= self::CONFIG_CHANGE_CORROBORATION_SECONDS;
    }

    /**
     * Linear decay of the onset gap; the asymmetry is exactly one choice — which
     * horizon divides the gap: the full one for a symptom that follows the cause,
     * the short jitter tolerance for one that precedes it.
     */
    private static function proximityScore(int $delta): float
    {
        $horizon = $delta >= 0 ? self::PROXIMITY_HORIZON_SECONDS : self::ONSET_JITTER_TOLERANCE_SECONDS;

        return max(0.0, 1.0 - abs($delta) / $horizon);
    }

    /**
     * A censored estimate is only trusted for a demotion the censoring cannot
     * overturn. Censoring moves the true gap in one known direction per side: a
     * censored symptom onset can only be earlier (true gap ≤ observed), a
     * censored cause onset can only be earlier (true gap ≥ observed). A low
     * score survives only when every reachable true gap stays in its low region
     * — with both sides censored the true gap is unbounded both ways, so nothing
     * survives. Callers guarantee at least one side is censored.
     */
    private static function demotionSurvivesCensoring(
        float $score,
        int $delta,
        bool $causeCensored,
        bool $symptomCensored,
    ): bool {
        if (self::bandFor($score) !== self::BAND_LOW) {
            return false;
        }

        if ($causeCensored && $symptomCensored) {
            return false;
        }

        return $symptomCensored ? $delta <= 0 : $delta >= 0;
    }

    private static function bandFor(float $score): string
    {
        if ($score >= self::HIGH_FLOOR) {
            return self::BAND_HIGH;
        }

        if ($score < self::LOW_CEILING) {
            return self::BAND_LOW;
        }

        return self::BAND_MEDIUM;
    }
}
