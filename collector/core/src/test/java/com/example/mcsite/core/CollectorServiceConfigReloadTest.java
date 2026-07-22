package com.example.mcsite.core;

import org.junit.Test;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.attribute.FileTime;
import java.util.Arrays;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;

public class CollectorServiceConfigReloadTest {
    @Test
    public void appliesChangedTomlWithoutRestartingService() throws Exception {
        Path gameDirectory = Files.createTempDirectory("mc-site-game");
        Path configDirectory = gameDirectory.resolve("config");
        Path path = configDirectory.resolve("mc-official-site.toml");
        CollectorConfig config = CollectorConfig.load(path);
        CollectorService service = new CollectorService(gameDirectory, configDirectory, config);

        service.reloadConfigIfChanged();
        Files.write(path, Arrays.asList(
            "[endpoint]",
            "site_url = \"https://example.com\"",
            "token = \"reloaded-token\"",
            "[timing]",
            "upload_interval_seconds = 7",
            "[features]",
            "collect_network = false"
        ), StandardCharsets.UTF_8);
        Files.setLastModifiedTime(path, FileTime.fromMillis(System.currentTimeMillis() + 2000L));

        service.reloadConfigIfChanged();

        assertEquals("reloaded-token", config.token);
        assertEquals(7, config.uploadIntervalSeconds);
        assertFalse(config.collectNetwork);
    }
}
