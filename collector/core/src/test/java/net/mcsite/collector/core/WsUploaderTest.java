package net.mcsite.collector.core;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import org.junit.Test;

import java.io.IOException;
import java.util.List;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertThrows;
import static org.junit.Assert.assertTrue;

/**
 * 验证 WSS 通道的认证与信封协议。测试使用本机的明文 {@code ws://} 服务端，
 * 生产环境只会连接 {@code wss://}，因为配置校验要求站点地址是 https。
 */
public class WsUploaderTest {
    @Test
    public void authenticatesOnceThenSendsEnvelopeAndAcceptsConfirmation() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer(TestTelemetryServer::respondAsCollectorServer);
        try (server; WsUploader uploader = new WsUploader(configFor(server, "test-token"))) {
            JsonObject payload = new JsonObject();
            payload.addProperty("online", true);
            payload.addProperty("online_count", 3);

            uploader.upload("status", payload);

            List<String> messages = server.collectorMessages();
            assertEquals("应当先认证再发信封", 2, messages.size());

            JsonObject authentication = parse(messages.get(0));
            assertEquals("authenticate", authentication.get("action").getAsString());
            assertEquals("test-token", authentication.get("token").getAsString());

            JsonObject envelope = parse(messages.get(1));
            String messageId = envelope.get("id").getAsString();
            assertTrue("id 必须匹配服务端的字符集校验", messageId.matches("^[A-Za-z0-9._:-]+$"));
            assertTrue("id 长度不得超过 80", messageId.length() <= 80);
            assertEquals("status", envelope.get("type").getAsString());
            assertEquals(3, envelope.getAsJsonObject("payload").get("online_count").getAsInt());
            assertEquals(1, server.websocketConnectionCount());
        }
    }

    @Test
    public void reportsAuthenticationRejection() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer(
            (message, connectionIndex) -> "{\"ok\":false,\"error\":\"unauthorized\"}");
        try (server; WsUploader uploader = new WsUploader(configFor(server, "wrong-token"))) {
            IOException failure = assertThrows(IOException.class, () -> uploader.upload("status", new JsonObject()));
            assertTrue(failure.getMessage(), failure.getMessage().contains("unauthorized"));
        }
    }

    @Test
    public void reportsEnvelopeRejection() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer((message, connectionIndex) ->
            isAuthenticate(parse(message))
                ? "{\"ok\":true,\"action\":\"ready\"}"
                : "{\"ok\":false,\"error\":\"invalid_payload\"}");
        try (server; WsUploader uploader = new WsUploader(configFor(server, "test-token"))) {
            IOException failure = assertThrows(IOException.class, () -> uploader.upload("status", new JsonObject()));
            assertTrue(failure.getMessage(), failure.getMessage().contains("invalid_payload"));
        }
    }

    @Test
    public void rejectsUnsupportedEnvelopeTypeBeforeConnecting() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer(TestTelemetryServer::respondAsCollectorServer);
        try (server; WsUploader uploader = new WsUploader(configFor(server, "test-token"))) {
            assertThrows(IllegalArgumentException.class, () -> uploader.upload("not_a_real_type", new JsonObject()));
            assertEquals("类型不合法时不应建立连接", 0, server.websocketConnectionCount());
        }
    }

    @Test
    public void keepsOneWebSocketForConsecutiveUploads() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer(TestTelemetryServer::respondAsCollectorServer);
        try (server; WsUploader uploader = new WsUploader(configFor(server, "test-token"))) {
            for (int round = 0; round < 3; round++) {
                JsonObject payload = new JsonObject();
                payload.addProperty("round", round);
                uploader.upload("status", payload);
            }

            assertEquals("连续上报应复用同一条连接", 1, server.websocketConnectionCount());
            assertEquals("1 次认证加 3 个信封", 4, server.collectorMessages().size());
        }
    }

    @Test
    public void surfacesConnectionLossWhileWaitingForConfirmation() throws Exception {
        TestTelemetryServer server = new TestTelemetryServer((message, connectionIndex) -> {
            if (isAuthenticate(parse(message))) {
                return "{\"ok\":true,\"action\":\"ready\"}";
            }
            // 模拟服务端收到信封后直接断开连接。
            throw new IllegalStateException("drop connection");
        });
        try (server; WsUploader uploader = new WsUploader(configFor(server, "test-token"))) {
            IOException failure = assertThrows(IOException.class, () -> uploader.upload("status", new JsonObject()));
            assertTrue(failure.getMessage(), failure.getMessage().contains("WebSocket"));
        }
    }

    private static CollectorConfig configFor(TestTelemetryServer server, String token) {
        CollectorConfig config = new CollectorConfig();
        config.siteUrl = server.baseUrl();
        config.token = token;
        config.connectTimeoutMillis = 2000;
        config.readTimeoutMillis = 2000;
        return config;
    }

    private static boolean isAuthenticate(JsonObject request) {
        JsonElement action = request.get("action");
        return action != null && "authenticate".equals(action.getAsString());
    }

    private static JsonObject parse(String message) {
        return JsonParser.parseString(message).getAsJsonObject();
    }
}
