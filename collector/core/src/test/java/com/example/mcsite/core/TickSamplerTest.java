package com.example.mcsite.core;

import org.junit.Test;

import static org.junit.Assert.assertEquals;

public class TickSamplerTest {
    @Test
    public void calculatesRecentPercentiles() {
        TickSampler sampler = new TickSampler();
        sampler.recordMilliseconds(1.0D);
        sampler.recordMilliseconds(2.0D);
        sampler.recordMilliseconds(3.0D);

        TickSampler.Values values = sampler.lastSecond();
        assertEquals(2.0D, values.average, 0.0001D);
        assertEquals(2.0D, values.p50, 0.0001D);
        assertEquals(3.0D, values.p95, 0.0001D);
        assertEquals(3.0D, values.p99, 0.0001D);
    }

    @Test
    public void ignoresInvalidSamples() {
        TickSampler sampler = new TickSampler();
        sampler.recordMilliseconds(Double.NaN);
        sampler.recordMilliseconds(-1.0D);
        sampler.recordMilliseconds(5.0D);
        assertEquals(5.0D, sampler.lastSecond().average, 0.0001D);
    }
}