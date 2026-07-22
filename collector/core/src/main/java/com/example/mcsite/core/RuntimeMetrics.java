package com.example.mcsite.core;

import java.io.BufferedReader;
import java.io.File;
import java.io.InputStreamReader;
import java.lang.management.ManagementFactory;
import java.lang.reflect.Method;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public final class RuntimeMetrics {
    private static final Pattern TWO_NUMBERS = Pattern.compile("^\\s*[^0-9]*([0-9][0-9,.]*)\\s+([0-9][0-9,.]*)\\s*$");
    private long previousReceived = -1L;
    private long previousSent = -1L;
    private long previousNetworkAt = -1L;

    public Values sample(Path gameDirectory, boolean collectNetwork) {
        Object bean = ManagementFactory.getOperatingSystemMXBean();
        double processCpu = invokeDouble(bean, "getProcessCpuLoad", Double.NaN) * 100.0D;
        double hostCpu = invokeDouble(bean, "getSystemCpuLoad", Double.NaN) * 100.0D;
        long totalMemory = invokeLong(bean, "getTotalPhysicalMemorySize", -1L);
        long freeMemory = invokeLong(bean, "getFreePhysicalMemorySize", -1L);
        long usedMemory = totalMemory >= 0L && freeMemory >= 0L ? totalMemory - freeMemory : -1L;
        long processMemory = Runtime.getRuntime().totalMemory() - Runtime.getRuntime().freeMemory();
        File disk = gameDirectory.toAbsolutePath().toFile();
        long diskTotal = disk.getTotalSpace();
        long diskUsed = diskTotal - disk.getUsableSpace();
        long[] network = collectNetwork ? networkTotals() : new long[] { -1L, -1L };
        long now = System.nanoTime();
        double receiveRate = Double.NaN;
        double sendRate = Double.NaN;
        if (collectNetwork && previousNetworkAt > 0L && network[0] >= previousReceived && network[1] >= previousSent) {
            double elapsed = (now - previousNetworkAt) / 1_000_000_000.0D;
            if (elapsed > 0.0D) {
                receiveRate = (network[0] - previousReceived) / elapsed;
                sendRate = (network[1] - previousSent) / elapsed;
            }
        }
        previousReceived = network[0];
        previousSent = network[1];
        previousNetworkAt = now;
        return new Values(
            processCpu, processMemory, hostCpu, usedMemory, totalMemory,
            diskUsed, diskTotal, network[0], network[1], receiveRate, sendRate
        );
    }

    private static double invokeDouble(Object target, String name, double fallback) {
        try {
            Method method = managementMethod(target, name);
            return ((Number) method.invoke(target)).doubleValue();
        } catch (Exception ignored) {
            return fallback;
        }
    }

    private static long invokeLong(Object target, String name, long fallback) {
        try {
            Method method = managementMethod(target, name);
            return ((Number) method.invoke(target)).longValue();
        } catch (Exception ignored) {
            return fallback;
        }
    }

    private static Method managementMethod(Object target, String name) throws Exception {
        try {
            Class<?> extendedBean = Class.forName("com.sun.management.OperatingSystemMXBean");
            if (extendedBean.isInstance(target)) {
                return extendedBean.getMethod(name);
            }
        } catch (ClassNotFoundException ignored) {
        }
        return target.getClass().getMethod(name);
    }

    private static long[] networkTotals() {
        String os = System.getProperty("os.name", "").toLowerCase();
        if (os.contains("linux")) {
            return linuxNetworkTotals();
        }
        if (os.contains("windows")) {
            return commandNetworkTotals();
        }
        return new long[] { 0L, 0L };
    }

    private static long[] linuxNetworkTotals() {
        long received = 0L;
        long sent = 0L;
        try {
            for (String line : Files.readAllLines(Paths.get("/proc/net/dev"), StandardCharsets.US_ASCII)) {
                int colon = line.indexOf(':');
                if (colon < 0) {
                    continue;
                }
                String[] values = line.substring(colon + 1).trim().split("\\s+");
                if (values.length >= 9) {
                    received += Long.parseLong(values[0]);
                    sent += Long.parseLong(values[8]);
                }
            }
        } catch (Exception ignored) {
        }
        return new long[] { received, sent };
    }

    private static long[] commandNetworkTotals() {
        Process process = null;
        try {
            process = new ProcessBuilder("netstat", "-e").redirectErrorStream(true).start();
            try (BufferedReader reader = new BufferedReader(new InputStreamReader(process.getInputStream(), StandardCharsets.UTF_8))) {
                String line;
                while ((line = reader.readLine()) != null) {
                    Matcher matcher = TWO_NUMBERS.matcher(line);
                    if (matcher.matches()) {
                        return new long[] { number(matcher.group(1)), number(matcher.group(2)) };
                    }
                }
            }
        } catch (Exception ignored) {
        } finally {
            if (process != null) {
                process.destroy();
            }
        }
        return new long[] { 0L, 0L };
    }

    private static long number(String value) {
        return Long.parseLong(value.replace(",", "").replace(".", ""));
    }

    public static final class Values {
        public final double processCpuPercent;
        public final long processMemoryBytes;
        public final double hostCpuPercent;
        public final long hostMemoryUsedBytes;
        public final long hostMemoryTotalBytes;
        public final long diskUsedBytes;
        public final long diskTotalBytes;
        public final long networkReceivedBytes;
        public final long networkSentBytes;
        public final double networkReceiveRate;
        public final double networkSendRate;

        Values(double processCpuPercent, long processMemoryBytes, double hostCpuPercent,
               long hostMemoryUsedBytes, long hostMemoryTotalBytes, long diskUsedBytes,
               long diskTotalBytes, long networkReceivedBytes, long networkSentBytes,
               double networkReceiveRate, double networkSendRate) {
            this.processCpuPercent = processCpuPercent;
            this.processMemoryBytes = processMemoryBytes;
            this.hostCpuPercent = hostCpuPercent;
            this.hostMemoryUsedBytes = hostMemoryUsedBytes;
            this.hostMemoryTotalBytes = hostMemoryTotalBytes;
            this.diskUsedBytes = diskUsedBytes;
            this.diskTotalBytes = diskTotalBytes;
            this.networkReceivedBytes = networkReceivedBytes;
            this.networkSentBytes = networkSentBytes;
            this.networkReceiveRate = networkReceiveRate;
            this.networkSendRate = networkSendRate;
        }
    }
}
