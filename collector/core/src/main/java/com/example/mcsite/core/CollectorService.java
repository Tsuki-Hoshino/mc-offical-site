package com.example.mcsite.core;

import com.google.gson.JsonArray;
import com.google.gson.JsonObject;

import java.io.PrintWriter;
import java.io.StringWriter;
import java.lang.management.ManagementFactory;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardOpenOption;
import java.time.Instant;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ThreadFactory;
import java.util.concurrent.TimeUnit;

public final class CollectorService {
    private final Path gameDirectory;
    private final Path errorLog;
    private final Path configPath;
    private final CollectorConfig config;
    private final HttpUploader uploader;
    private final StatsScanner statsScanner;
    private final RuntimeMetrics runtimeMetrics = new RuntimeMetrics();
    private final TickSampler tickSampler = new TickSampler();
    private final Map<String, Instant> onlineSince = new HashMap<String, Instant>();
    private volatile ServerSnapshot snapshot = ServerSnapshot.OFFLINE;
    private ScheduledExecutorService executor;
    private long lastErrorAt;
    private String lastError = "";
    private long lastStatsScanAt;
    private long lastStatusUploadAt;
    private long configSignature = Long.MIN_VALUE;

    public CollectorService(Path gameDirectory, Path configDirectory, CollectorConfig config) {
        this.gameDirectory = gameDirectory;
        this.errorLog = configDirectory.resolve("mc-site-collector-errors.log");
        this.configPath = configDirectory.resolve("mc-official-site.toml");
        this.config = config;
        this.uploader = new HttpUploader(config);
        this.statsScanner = new StatsScanner(gameDirectory, configDirectory);
    }

    public TickSampler ticks() {
        return tickSampler;
    }

    public synchronized void updateSnapshot(ServerSnapshot next) {
        snapshot = next;
        Set<String> active = new HashSet<String>();
        Instant now = Instant.now();
        for (PlayerInfo player : next.players) {
            String key = player.name.toLowerCase();
            active.add(key);
            if (!onlineSince.containsKey(key)) {
                onlineSince.put(key, now);
            }
        }
        onlineSince.keySet().retainAll(active);
    }

    public synchronized void start() {
        if (executor != null) {
            return;
        }
        executor = Executors.newSingleThreadScheduledExecutor(new ThreadFactory() {
            @Override
            public Thread newThread(Runnable runnable) {
                Thread thread = new Thread(runnable, "mc-site-collector");
                thread.setDaemon(true);
                return thread;
            }
        });
        executor.scheduleAtFixedRate(new Runnable() {
            @Override
            public void run() {
                uploadRound();
            }
        }, 0L, 1L, TimeUnit.SECONDS);
    }

    public synchronized void stop() {
        if (executor != null) {
            executor.shutdownNow();
            executor = null;
        }
        snapshot = ServerSnapshot.OFFLINE;
        onlineSince.clear();
    }

    private void uploadRound() {
        try {
            reloadConfigIfChanged();
            if (!config.isConfigured()) {
                return;
            }
            long now = System.currentTimeMillis();
            if (config.syncStatus && now - lastStatusUploadAt >= config.uploadIntervalSeconds * 1000L) {
                lastStatusUploadAt = now;
                uploader.upload("status", statusPayload());
            }
            if (config.syncPlayerStats && now - lastStatsScanAt >= config.statsScanIntervalSeconds * 1000L) {
                lastStatsScanAt = now;
                JsonObject stats = statsScanner.scanIfChanged();
                if (stats != null) {
                    uploader.upload("stats", stats);
                }
            }
        } catch (Throwable error) {
            recordError(error);
        }
    }

    void reloadConfigIfChanged() throws Exception {
        if (!Files.exists(configPath)) {
            return;
        }
        long signature = Files.getLastModifiedTime(configPath).toMillis() ^ Files.size(configPath);
        if (signature == configSignature) {
            return;
        }
        CollectorConfig next = CollectorConfig.load(configPath);
        config.apply(next);
        configSignature = signature;
    }

    private synchronized JsonObject statusPayload() {
        ServerSnapshot current = snapshot;
        TickSampler.Values tick = tickSampler.lastSecond();
        RuntimeMetrics.Values runtime = runtimeMetrics.sample(gameDirectory, config.collectNetwork);
        JsonArray players = new JsonArray();
        JsonArray bots = new JsonArray();
        JsonObject since = new JsonObject();
        for (PlayerInfo player : current.players) {
            (player.fake ? bots : players).add(player.name);
            Instant started = onlineSince.get(player.name.toLowerCase());
            if (started != null) {
                since.addProperty(player.name, started.toString());
            }
        }
        JsonObject skins = new JsonObject();
        for (Map.Entry<String, String> entry : statsScanner.skinsByName().entrySet()) {
            skins.addProperty(entry.getKey(), entry.getValue());
        }
        JsonObject runtimeJson = new JsonObject();
        runtimeJson.addProperty("server_time", Instant.now().toString());
        runtimeJson.addProperty("uptime_seconds", ManagementFactory.getRuntimeMXBean().getUptime() / 1000L);
        number(runtimeJson, "mspt", tick.average);
        number(runtimeJson, "mspt_p50", tick.p50);
        number(runtimeJson, "mspt_p95", tick.p95);
        number(runtimeJson, "mspt_p99", tick.p99);
        number(runtimeJson, "tps", Double.isNaN(tick.average) ? Double.NaN : Math.min(20.0D, 1000.0D / Math.max(50.0D, tick.average)));
        number(runtimeJson, "process_cpu_percent", runtime.processCpuPercent);
        runtimeJson.addProperty("process_memory_bytes", runtime.processMemoryBytes);
        number(runtimeJson, "host_cpu_percent", runtime.hostCpuPercent);
        integer(runtimeJson, "host_memory_used_bytes", runtime.hostMemoryUsedBytes);
        integer(runtimeJson, "host_memory_total_bytes", runtime.hostMemoryTotalBytes);
        integer(runtimeJson, "disk_used_bytes", runtime.diskUsedBytes);
        integer(runtimeJson, "disk_total_bytes", runtime.diskTotalBytes);
        integer(runtimeJson, "network_received_bytes", runtime.networkReceivedBytes);
        integer(runtimeJson, "network_sent_bytes", runtime.networkSentBytes);
        number(runtimeJson, "network_receive_rate", runtime.networkReceiveRate);
        number(runtimeJson, "network_send_rate", runtime.networkSendRate);

        JsonObject telemetry = new JsonObject();
        telemetry.addProperty("producer", "mc_official_site_collector");
        telemetry.addProperty("transport", "https_push");

        JsonObject payload = new JsonObject();
        payload.addProperty("generated_at", Instant.now().toString());
        payload.addProperty("motd", current.motd);
        payload.addProperty("online", current.online);
        payload.addProperty("online_count", players.size());
        payload.add("online_players", players);
        payload.addProperty("bot_count", bots.size());
        payload.add("bots", bots);
        payload.add("skin_urls", skins);
        payload.add("online_since", since);
        payload.add("runtime", runtimeJson);
        payload.add("telemetry", telemetry);
        return payload;
    }

    private static void number(JsonObject object, String key, double value) {
        if (Double.isNaN(value) || Double.isInfinite(value) || value < 0.0D) {
            object.add(key, null);
        } else {
            object.addProperty(key, Math.round(value * 100.0D) / 100.0D);
        }
    }

    private static void integer(JsonObject object, String key, long value) {
        if (value < 0L) {
            object.add(key, null);
        } else {
            object.addProperty(key, value);
        }
    }

    private synchronized void recordError(Throwable error) {
        long now = System.currentTimeMillis();
        String message = error.getClass().getName() + ": " + String.valueOf(error.getMessage());
        if (message.equals(lastError) && now - lastErrorAt < 60_000L) {
            return;
        }
        lastError = message;
        lastErrorAt = now;
        try {
            Files.createDirectories(errorLog.getParent());
            StringWriter trace = new StringWriter();
            error.printStackTrace(new PrintWriter(trace));
            Files.write(
                errorLog,
                ("[" + Instant.now() + "] " + trace + System.lineSeparator()).getBytes(StandardCharsets.UTF_8),
                StandardOpenOption.CREATE,
                StandardOpenOption.APPEND
            );
        } catch (Exception ignored) {
        }
    }
}
