package net.mcsite.collector.core;

import com.google.gson.JsonObject;
import org.junit.Test;

import java.io.IOException;
import java.util.Locale;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertThrows;
import static org.junit.Assert.assertTrue;

/**
 * 验证 HTTPS 回退通道走的是 JDK 的 HttpClient：连接由连接池复用，
 * 而不是像 HttpsURLConnection 那样每帧重新握手。
 */
public class HttpUploaderTest {
    @Test
    public void reusesOneTcpConnectionAcrossUploads() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer((message, connectionIndex) -> null);
        try (server; HttpUploader uploader = new HttpUploader(configFor(server))) {
            for (int round = 0; round < 3; round++) {
                JsonObject payload = new JsonObject();
                payload.addProperty("round", round);
                uploader.upload("status", payload);
            }

            assertEquals(3, server.httpRequestCount());
            assertEquals("三次上报应复用同一条 TCP 连接", 1, server.distinctHttpClientPortCount());
        }
    }

    @Test
    public void sendsJsonContentTypeAndSyncTokenHeader() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer((message, connectionIndex) -> null);
        try (server; HttpUploader uploader = new HttpUploader(configFor(server))) {
            uploader.upload("status", new JsonObject());

            String head = server.httpRequestHeads().get(0).toLowerCase(Locale.ROOT);
            assertTrue(head, head.startsWith("post /api/push.php?type=status "));
            assertTrue(head, head.contains("content-type: application/json; charset=utf-8"));
            assertTrue(head, head.contains("x-mc-sync-token: test-token"));
        }
    }

    @Test
    public void reportsNonSuccessStatus() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer((message, connectionIndex) -> null);
        server.setHttpStatus(503);
        try (server; HttpUploader uploader = new HttpUploader(configFor(server))) {
            IOException failure = assertThrows(IOException.class, () -> uploader.upload("status", new JsonObject()));
            assertTrue(failure.getMessage(), failure.getMessage().contains("503"));
        }
    }

    @Test
    public void followsEndpointChangeAfterConfigurationReload() throws Exception {
        TestTelemetryServer original = new TestTelemetryServer((message, connectionIndex) -> null);
        TestTelemetryServer replacement = new TestTelemetryServer((message, connectionIndex) -> null);
        try (original; replacement) {
            CollectorConfig config = configFor(original);
            try (HttpUploader uploader = new HttpUploader(config)) {
                uploader.upload("status", new JsonObject());

                config.siteUrl = replacement.baseUrl();
                uploader.upload("status", new JsonObject());
            }

            assertEquals("切换站点地址后不应再请求旧端点", 1, original.httpRequestCount());
            assertEquals(1, replacement.httpRequestCount());
        }
    }

    private static CollectorConfig configFor(TestTelemetryServer server) {
        CollectorConfig config = new CollectorConfig();
        config.siteUrl = server.baseUrl();
        config.token = "test-token";
        config.connectTimeoutMillis = 2000;
        config.readTimeoutMillis = 2000;
        return config;
    }
}
