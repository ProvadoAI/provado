<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Diagnosis;

/**
 * A learned-normal baseline for one metric, computed from its series of readings.
 *
 * The architecture doc (Part D) asks the diagnosis layer to "learn normal …
 * instead of static thresholds, so a slow drift is caught before it trips a hard
 * limit." Static thresholds fail in both directions: a normally-low metric that
 * drifts upward stays under the hard limit (missed incident), and a metric whose
 * normal level is legitimately high trips the limit forever (false alarm — e.g.
 * the lab's `cron_schedule` carries thousands of historical errored rows that are
 * not an active incident).
 *
 * The statistics are robust on purpose: median for the centre and the scaled
 * median-absolute-deviation (MAD) for the spread, so a few spike samples don't
 * drag the baseline up and mask later spikes. A relative spread floor stops a
 * near-constant series (MAD ≈ 0) from flagging trivial noise.
 */
final readonly class MetricBaseline
{
    /** Standard scale factor making MAD a consistent estimator of σ for normal data. */
    private const MAD_TO_SIGMA = 1.4826;

    /** How many spreads above the median counts as anomalously high. */
    private const SIGMAS = 3.0;

    /** Minimum spread as a fraction of |median|, so a flat series needs a real jump. */
    private const RELATIVE_SPREAD_FLOOR = 0.2;

    /**
     * @var list<float>
     */
    public array $samples;

    /**
     * @param list<int|float> $samples
     */
    public function __construct(array $samples)
    {
        $this->samples = array_values(array_map(static fn (int|float $v): float => (float) $v, $samples));
    }

    public function sampleCount(): int
    {
        return count($this->samples);
    }

    public function median(): float
    {
        return $this->medianOf($this->samples);
    }

    /**
     * Scaled median absolute deviation — a robust, outlier-resistant spread.
     */
    public function scaledMad(): float
    {
        if ($this->samples === []) {
            return 0.0;
        }

        $median = $this->median();
        $deviations = array_map(static fn (float $v): float => abs($v - $median), $this->samples);

        return $this->medianOf($deviations) * self::MAD_TO_SIGMA;
    }

    /**
     * The upper bound of normal: median plus a few spreads, where the spread is at
     * least a fraction of the median so an essentially-constant series still needs
     * a meaningful jump to read as high.
     */
    public function high(): float
    {
        $median = $this->median();
        $spread = max($this->scaledMad(), self::RELATIVE_SPREAD_FLOOR * abs($median));

        return $median + self::SIGMAS * $spread;
    }

    public function isHigh(int|float $value): bool
    {
        return (float) $value > $this->high();
    }

    /**
     * @param list<float> $values
     */
    private function medianOf(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
