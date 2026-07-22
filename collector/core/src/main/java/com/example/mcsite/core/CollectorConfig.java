package com.example.mcsite.core;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Arrays;
import java.util.List;

public final class CollectorConfig {
    public volatile String siteUrl = "https://mc.example.com";
    public volatile String token = "";
    public volatile int sampleIntervalTicks = 1;
    public volatile int uploadIntervalSeconds = 1;
    public volatile int statsScanIntervalSeconds = 1;
    public volatile int connectTimeoutMillis = 5000;
    public volatile int readTimeoutMillis = 8000;
    public volatile boolean syncStatus = true;
    public volatile boolean syncPlayerStats = true;
    public volatile boolean collectNetwork = true;
    public volatile String fakeClassKeywords = "fake,carpet";
    public volatile String fakeDisplayPrefixes = "\u5047\u7684";

    public static CollectorConfig load(Path path) throws IOException {
        if (!Files.exists(path)) {
            Files.createDirectories(path.getParent());
            CollectorConfig defaults = new CollectorConfig();
            Files.write(path, defaults.template(), StandardCharsets.UTF_8);
            return defaults;
        }
        CollectorConfig config = new CollectorConfig();
        for (String rawLine : Files.readAllLines(path, StandardCharsets.UTF_8)) {
            String line = rawLine.trim();
            if (line.isEmpty() || line.startsWith("#") || line.startsWith("[")) {
                continue;
            }
            int separator = line.indexOf('=');
            if (separator < 1) {
                continue;
            }
            String key = line.substring(0, separator).trim();
            String value = line.substring(separator + 1).trim();
            config.assign(key, value);
        }
        config.sampleIntervalTicks = Math.max(1, config.sampleIntervalTicks);
        config.uploadIntervalSeconds = Math.max(1, config.uploadIntervalSeconds);
        config.statsScanIntervalSeconds = Math.max(1, config.statsScanIntervalSeconds);
        config.connectTimeoutMillis = Math.max(1000, config.connectTimeoutMillis);
        config.readTimeoutMillis = Math.max(1000, config.readTimeoutMillis);
        return config;
    }

    public void apply(CollectorConfig next) {
        siteUrl = next.siteUrl;
        token = next.token;
        sampleIntervalTicks = next.sampleIntervalTicks;
        uploadIntervalSeconds = next.uploadIntervalSeconds;
        statsScanIntervalSeconds = next.statsScanIntervalSeconds;
        connectTimeoutMillis = next.connectTimeoutMillis;
        readTimeoutMillis = next.readTimeoutMillis;
        syncStatus = next.syncStatus;
        syncPlayerStats = next.syncPlayerStats;
        collectNetwork = next.collectNetwork;
        fakeClassKeywords = next.fakeClassKeywords;
        fakeDisplayPrefixes = next.fakeDisplayPrefixes;
    }
    public boolean isConfigured() {
        return siteUrl != null && siteUrl.startsWith("https://")
            && token != null && !token.trim().isEmpty();
    }

    private void assign(String key, String raw) {
        String value = unquote(raw);
        try {
            if ("site_url".equals(key)) siteUrl = value;
            else if ("token".equals(key)) token = value;
            else if ("sample_interval_ticks".equals(key)) sampleIntervalTicks = Integer.parseInt(value);
            else if ("upload_interval_seconds".equals(key)) uploadIntervalSeconds = Integer.parseInt(value);
            else if ("stats_scan_interval_seconds".equals(key)) statsScanIntervalSeconds = Integer.parseInt(value);
            else if ("connect_timeout_millis".equals(key)) connectTimeoutMillis = Integer.parseInt(value);
            else if ("read_timeout_millis".equals(key)) readTimeoutMillis = Integer.parseInt(value);
            else if ("sync_status".equals(key)) syncStatus = Boolean.parseBoolean(value);
            else if ("sync_player_stats".equals(key)) syncPlayerStats = Boolean.parseBoolean(value);
            else if ("collect_network".equals(key)) collectNetwork = Boolean.parseBoolean(value);
            else if ("fake_class_keywords".equals(key)) fakeClassKeywords = value;
            else if ("fake_display_prefixes".equals(key)) fakeDisplayPrefixes = value;
        } catch (RuntimeException ignored) {
        }
    }

    private static String unquote(String value) {
        int comment = value.indexOf(" #");
        if (comment >= 0) value = value.substring(0, comment).trim();
        if (value.length() >= 2 && value.startsWith("\"") && value.endsWith("\"")) {
            return value.substring(1, value.length() - 1).replace("\\\"", "\"").replace("\\\\", "\\");
        }
        return value;
    }

    private List<String> template() {
        return Arrays.asList(
            "# MC Official Site Collector",
            "[endpoint]",
            "site_url = \"" + siteUrl + "\"",
            "token = \"\"",
            "",
            "[timing]",
            "# 1 means sample every game tick. Uploads remain batched.",
            "sample_interval_ticks = 1",
            "upload_interval_seconds = 1",
            "stats_scan_interval_seconds = 1",
            "",
            "[features]",
            "sync_status = true",
            "sync_player_stats = true",
            "collect_network = true",
            "",
            "[fake_players]",
            "fake_class_keywords = \"fake,carpet\"",
            "fake_display_prefixes = \"\u5047\u7684\"",
            "",
            "[http]",
            "connect_timeout_millis = 5000",
            "read_timeout_millis = 8000"
        );
    }
}
