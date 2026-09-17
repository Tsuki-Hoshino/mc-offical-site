package net.mcsite.collector.core;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import org.junit.Test;

import java.io.IOException;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertThrows;
import static org.junit.Assert.assertTrue;

/** 验证传输选择策略：优先 WSS，失败回退 HTTPS，并在重试间隔内不再重连 WSS。 */
public class TransportUploaderTest {
    @Test
    public void prefersWssAndRecordsTheRealTransport() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer(TestTelemetryServer::respondAsCollectorServer);
        try (server; TransportUploader uploader = new TransportUploader(configFor(server))) {
            JsonObject payload = statusPayload();

            assertEquals(WsUploader.TRANSPORT_NAME, uploader.upload("status", payload));
            assertEquals("wss", payload.getAsJsonObject("telemetry").get("transport").getAsString());
            assertEquals("WSS 可用时不应触发 HTTPS 回退", 0, server.httpRequestCount());

            JsonObject envelope = JsonParser.parseString(server.collectorMessages().get(1)).getAsJsonObject();
            assertEquals("发往服务端的载荷也要标明真实通道", "wss",
                envelope.getAsJsonObject("payload").getAsJsonObject("telemetry").get("transport").getAsString());
        }
    }

    @Test
    public void fallsBackToHttpsAndMarksTheFallbackTransport() throws Exception {
        TestTelemetryServer server = refusingServer();
        try (server; TransportUploader uploader = new TransportUploader(configFor(server))) {
            JsonObject payload = statusPayload();

            assertEquals(HttpUploader.TRANSPORT_NAME, uploader.upload("status", payload));
            assertEquals("https_push", payload.getAsJsonObject("telemetry").get("transport").getAsString());
            assertEquals(1, server.websocketConnectionCount());
            assertEquals(1, server.httpRequestCount());
        }
    }

    @Test
    public void doesNotReconnectWssOnEveryFrameAfterFailure() throws Exception {
        TestTelemetryServer server = refusingServer();
        try (server; TransportUploader uploader = new TransportUploader(configFor(server))) {
            assertEquals(HttpUploader.TRANSPORT_NAME, uploader.upload("status", statusPayload()));
            assertEquals(1, server.websocketConnectionCount());
            int httpRequestsAfterFirstFrame = server.httpRequestCount();

            assertEquals(HttpUploader.TRANSPORT_NAME, uploader.upload("status", statusPayload()));

            assertEquals("重试间隔内不应再次连接 WSS", 1, server.websocketConnectionCount());
            assertEquals(httpRequestsAfterFirstFrame + 1, server.httpRequestCount());
        }
    }

    @Test
    public void retriesWssAfterRetryIntervalElapsed() throws Exception {
        TestTelemetryServer server = refusingServer();
        CollectorConfig config = configFor(server);
        config.wssRetryIntervalSeconds = 1;
        try (server; TransportUploader uploader = new TransportUploader(config)) {
            assertEquals(HttpUploader.TRANSPORT_NAME, uploader.upload("status", statusPayload()));
            assertEquals(1, server.websocketConnectionCount());

            Thread.sleep(1200L);

            assertEquals(HttpUploader.TRANSPORT_NAME, uploader.upload("status", statusPayload()));
            assertEquals("超过重试间隔后应重新尝试 WSS", 2, server.websocketConnectionCount());
        }
    }

    @Test
    public void skipsWssEntirelyWhenDisabled() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer(TestTelemetryServer::respondAsCollectorServer);
        CollectorConfig config = configFor(server);
        config.wssEnabled = false;
        try (server; TransportUploader uploader = new TransportUploader(config)) {
            assertEquals(HttpUploader.TRANSPORT_NAME, uploader.upload("status", statusPayload()));

            assertEquals("关闭 WSS 后不应建立 WebSocket 连接", 0, server.websocketConnectionCount());
            assertEquals(1, server.httpRequestCount());
        }
    }

    @Test
    public void reportsTheWssReasonWhenBothChannelsFail() throws Exception {
        TestTelemetryServer server = refusingServer();
        server.setHttpStatus(503);
        try (server; TransportUploader uploader = new TransportUploader(configFor(server))) {
            JsonObject payload = statusPayload();

            IOException failure = assertThrows(IOException.class, () -> uploader.upload("status", payload));

            assertTrue(failure.getMessage(), failure.getMessage().contains("503"));
            assertEquals("WSS 的失败原因应作为抑制异常保留下来", 1, failure.getSuppressed().length);
            assertTrue(failure.getSuppressed()[0].getMessage().contains("unauthorized"));
        }
    }

    private static TestTelemetryServer refusingServer() throws IOException {
        return new TestTelemetryServer(
            (message, connectionIndex) -> "{\"ok\":false,\"error\":\"unauthorized\"}");
    }

    private static CollectorConfig configFor(TestTelemetryServer server) {
        CollectorConfig config = new CollectorConfig();
        config.siteUrl = server.baseUrl();
        config.token = "test-token";
        config.connectTimeoutMillis = 2000;
        config.readTimeoutMillis = 2000;
        return config;
    }

    private static JsonObject statusPayload() {
        JsonObject telemetry = new JsonObject();
        telemetry.addProperty("producer", "mc_official_site_collector");
        JsonObject payload = new JsonObject();
        payload.addProperty("online", true);
        payload.add("telemetry", telemetry);
        return payload;
    }
}
