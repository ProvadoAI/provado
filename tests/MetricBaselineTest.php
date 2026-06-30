<?php

declare(strict_types=1);

namespace Mquevedob\Provado\Tests;

use Mquevedob\Provado\Diagnosis\MetricBaseline;
use PHPUnit\Framework\TestCase;

class MetricBaselineTest extends TestCase
{
    public function test_median_of_odd_and_even_sample_counts(): void
    {
        $this->assertSame(3.0, (new MetricBaseline([5, 1, 3, 2, 4]))->median());
        $this->assertSame(2.5, (new MetricBaseline([1, 2, 3, 4]))->median());
    }

    public function test_scaled_mad_is_the_robust_spread(): void
    {
        // [1,2,3,4,5] → median 3, deviations [2,1,0,1,2] → median 1 → ×1.4826.
        $this->assertEqualsWithDelta(1.4826, (new MetricBaseline([1, 2, 3, 4, 5]))->scaledMad(), 0.0001);
    }

    public function test_a_stable_high_series_does_not_flag_its_own_level(): void
    {
        // The lab case: thousands of historical errored rows, essentially constant.
        // The level itself is normal; only a real jump above it is high.
        $baseline = new MetricBaseline([2791, 2801, 2855, 2791, 2810]);

        $this->assertFalse($baseline->isHigh(2820));
        $this->assertTrue($baseline->isHigh(6000));
    }

    public function test_drift_above_a_low_normal_is_flagged_even_below_a_static_limit(): void
    {
        // Normal pending backlog ~5; a drift to 80 is 16× normal and is caught,
        // though a static limit of 100 would have missed it.
        $baseline = new MetricBaseline([4, 5, 5, 6, 5]);

        $this->assertTrue($baseline->isHigh(80));
        $this->assertFalse($baseline->isHigh(6));
    }

    public function test_relative_spread_floor_ignores_trivial_noise_on_a_flat_series(): void
    {
        // median 100, MAD 0 → spread floor = 0.2×100, high = 100 + 3×20 = 160.
        $baseline = new MetricBaseline([100, 100, 100, 100]);

        $this->assertSame(160.0, $baseline->high());
        $this->assertFalse($baseline->isHigh(150));
        $this->assertTrue($baseline->isHigh(161));
    }

    public function test_empty_baseline_reports_zero_samples(): void
    {
        $baseline = new MetricBaseline([]);

        $this->assertSame(0, $baseline->sampleCount());
        $this->assertSame(0.0, $baseline->median());
        $this->assertSame(0.0, $baseline->high());
    }
}
