package com.example.mcsite.core;

import org.junit.Test;

import java.nio.file.Paths;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class RuntimeMetricsTest {
    @Test
    public void readsCpuMetricsThroughThePublicManagementInterface() {
        RuntimeMetrics.Values values = new RuntimeMetrics().sample(Paths.get("."), false);

        assertFalse(Double.isNaN(values.processCpuPercent));
        assertTrue(values.processCpuPercent >= 0.0D);
        assertFalse(Double.isNaN(values.hostCpuPercent));
        assertTrue(values.hostCpuPercent >= 0.0D);
    }
}
