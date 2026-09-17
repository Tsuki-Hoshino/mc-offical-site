package net.mcsite.collector.core;

import org.junit.Test;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Arrays;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public class CollectorConfigTest {
    @Test
    public void createsAndLoadsTomlConfiguration() throws Exception {
        Path directory = Files.createTempDirectory("mc-site-config");
        Path path = directory.resolve("config").resolve("mc-official-site.toml");

        CollectorConfig defaults = CollectorConfig.load(path);
        assertTrue(Files.isRegularFile(path));
        assertFalse(defaults.isConfigured());

        Files.write(path, Arrays.asList(
            "[endpoint]",
            "site_url = \"https://telemetry.invalid/\"",
            "token = \"secret\"",
            "[timing]",
            "sample_interval_ticks = 2",
            "upload_interval_seconds = 3",
            "[features]",
            "collect_network = false",
            "[fake_players]",
            "fake_display_prefixes = \"\u5047\u7684\""
        ), StandardCharsets.UTF_8);

        CollectorConfig loaded = CollectorConfig.load(path);
        assertTrue(loaded.isConfigured());
        assertEquals("https://telemetry.invalid/", loaded.siteUrl);
        assertEquals("secret", loaded.token);
        assertEquals(2, loaded.sampleIntervalTicks);
        assertEquals(3, loaded.uploadIntervalSeconds);
        assertFalse(loaded.collectNetwork);

        defaults.apply(loaded);
        assertEquals("secret", defaults.token);
        assertEquals(2, defaults.sampleIntervalTicks);
    }

    @Test
    public void rejectsNonHttpsEndpoint() {
        CollectorConfig config = new CollectorConfig();
        config.siteUrl = "http://telemetry.invalid";
        config.token = "secret";
        assertFalse(config.isConfigured());
    }

    @Test
    public void usesFiveSecondUploadIntervalAndEnabledWssByDefault() {
        CollectorConfig config = new CollectorConfig();

        assertEquals(5, config.uploadIntervalSeconds);
        assertTrue(config.wssEnabled);
        assertEquals(30, config.wssRetryIntervalSeconds);
    }

    @Test
    public void readsTransportSettingsAndAppliesThemToTheRunningConfig() throws Exception {
        Path directory = Files.createTempDirectory("mc-site-transport-config");
        Path path = directory.resolve("config").resolve("mc-official-site.toml");
        CollectorConfig.load(path);

        Files.write(path, Arrays.asList(
            "[endpoint]",
            "site_url = \"https://telemetry.invalid\"",
            "token = \"secret\"",
            "[transport]",
            "wss_enabled = false",
            "wss_retry_interval_seconds = 12"
        ), StandardCharsets.UTF_8);

        CollectorConfig loaded = CollectorConfig.load(path);
        assertFalse(loaded.wssEnabled);
        assertEquals(12, loaded.wssRetryIntervalSeconds);

        CollectorConfig running = new CollectorConfig();
        running.apply(loaded);
        assertFalse(running.wssEnabled);
        assertEquals(12, running.wssRetryIntervalSeconds);
    }

    @Test
    public void clampsWssRetryIntervalToAtLeastOneSecond() throws Exception {
        Path directory = Files.createTempDirectory("mc-site-retry-config");
        Path path = directory.resolve("config").resolve("mc-official-site.toml");
        CollectorConfig.load(path);

        Files.write(path, Arrays.asList(
            "[endpoint]",
            "site_url = \"https://telemetry.invalid\"",
            "token = \"secret\"",
            "[transport]",
            "wss_retry_interval_seconds = 0"
        ), StandardCharsets.UTF_8);

        assertEquals(1, CollectorConfig.load(path).wssRetryIntervalSeconds);
    }

    @Test
    public void writesTransportDefaultsIntoGeneratedTemplate() throws Exception {
        Path directory = Files.createTempDirectory("mc-site-template");
        Path path = directory.resolve("config").resolve("mc-official-site.toml");

        CollectorConfig.load(path);

        String template = Files.readString(path, StandardCharsets.UTF_8);
        assertTrue(template.contains("upload_interval_seconds = 5"));
        assertTrue(template.contains("wss_enabled = true"));
        assertTrue(template.contains("wss_retry_interval_seconds = 30"));
    }
}
