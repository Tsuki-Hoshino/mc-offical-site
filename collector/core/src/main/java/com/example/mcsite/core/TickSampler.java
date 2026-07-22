package com.example.mcsite.core;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

public final class TickSampler {
    private final double[] samples = new double[1200];
    private int size;
    private int cursor;
    private long startedAt;

    public synchronized void startTick() {
        startedAt = System.nanoTime();
    }

    public synchronized void endTick() {
        if (startedAt == 0L) {
            return;
        }
        samples[cursor] = (System.nanoTime() - startedAt) / 1_000_000.0D;
        cursor = (cursor + 1) % samples.length;
        if (size < samples.length) {
            size++;
        }
        startedAt = 0L;
    }

    public synchronized void recordMilliseconds(double milliseconds) {
        if (Double.isNaN(milliseconds) || Double.isInfinite(milliseconds) || milliseconds < 0.0D) {
            return;
        }
        samples[cursor] = milliseconds;
        cursor = (cursor + 1) % samples.length;
        if (size < samples.length) {
            size++;
        }
    }
    public synchronized Values lastSecond() {
        int count = Math.min(20, size);
        if (count == 0) {
            return Values.EMPTY;
        }
        List<Double> values = new ArrayList<Double>(count);
        double sum = 0.0D;
        for (int i = 0; i < count; i++) {
            int index = (cursor - 1 - i + samples.length) % samples.length;
            double value = samples[index];
            values.add(value);
            sum += value;
        }
        Collections.sort(values);
        return new Values(sum / count, percentile(values, .50D), percentile(values, .95D), percentile(values, .99D));
    }

    private static double percentile(List<Double> values, double percentile) {
        int index = (int) Math.ceil(percentile * values.size()) - 1;
        return values.get(Math.max(0, Math.min(values.size() - 1, index)));
    }

    public static final class Values {
        static final Values EMPTY = new Values(Double.NaN, Double.NaN, Double.NaN, Double.NaN);
        public final double average;
        public final double p50;
        public final double p95;
        public final double p99;

        Values(double average, double p50, double p95, double p99) {
            this.average = average;
            this.p50 = p50;
            this.p95 = p95;
            this.p99 = p99;
        }
    }
}
