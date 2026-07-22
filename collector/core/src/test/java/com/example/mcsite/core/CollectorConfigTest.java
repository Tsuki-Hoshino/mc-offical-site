package com.example.mcsite.core;

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
            "site_url = \"https://example.com/\"",
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
        assertEquals("https://example.com/", loaded.siteUrl);
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
        config.siteUrl = "http://example.com";
        config.token = "secret";
        assertFalse(config.isConfigured());
    }
}
