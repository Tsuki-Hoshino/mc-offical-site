package net.mcsite.collector.core;

import java.io.ByteArrayOutputStream;
import java.io.Closeable;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.ArrayList;
import java.util.Base64;
import java.util.Collections;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicInteger;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

/**
 * 单元测试用的极简服务端，在同一个端口上同时接受两种请求：
 *
 * <ul>
 *   <li>普通 HTTP POST，对应网站上的 {@code /api/push.php} 回退端点；</li>
 *   <li>WebSocket 握手与文本帧，对应 {@code /ws/collector} 上报通道。</li>
 * </ul>
 *
 * <p>之所以两种协议共用一个端口，是因为采集器从同一个 {@code site_url} 推导出两个端点，
 * 拆成两个端口就无法用同一份配置同时验证「优先 WSS」和「回退 HTTPS」。JDK 只提供 WebSocket
 * 客户端，所以测试端按 RFC 6455 处理握手与帧的收发；这些代码只存在于测试源码集。
 */
final class TestTelemetryServer implements Closeable {
    /** 处理一条客户端文本消息并返回要发回的文本；返回 null 表示不回复。 */
    interface MessageHandler {
        String respond(String message, int connectionIndex);
    }

    private static final String WEBSOCKET_GUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
    private static final int TEXT_OPCODE = 0x1;
    private static final int CLOSE_OPCODE = 0x8;
    private static final int MAX_FRAME_LENGTH = 1 << 20;

    private final ServerSocket serverSocket;
    private final ExecutorService workers;
    private final MessageHandler handler;
    private final AtomicInteger websocketConnections = new AtomicInteger();
    private final AtomicInteger httpRequests = new AtomicInteger();
    private final List<String> collectorMessages = Collections.synchronizedList(new ArrayList<>());
    private final List<Integer> httpClientPorts = Collections.synchronizedList(new ArrayList<>());
    private final List<String> httpRequestHeads = Collections.synchronizedList(new ArrayList<>());
    private volatile int httpStatus = 200;
    private volatile boolean running = true;

    TestTelemetryServer(MessageHandler handler) throws IOException {
        this.handler = handler;
        this.serverSocket = new ServerSocket();
        this.serverSocket.bind(new InetSocketAddress(InetAddress.getLoopbackAddress(), 0));
        this.workers = Executors.newCachedThreadPool(runnable -> {
            Thread thread = new Thread(runnable, "test-telemetry-server");
            thread.setDaemon(true);
            return thread;
        });
        Thread acceptor = new Thread(this::acceptLoop, "test-telemetry-acceptor");
        acceptor.setDaemon(true);
        acceptor.start();
    }

    String baseUrl() {
        return "http://127.0.0.1:" + serverSocket.getLocalPort();
    }

    /**
     * 模拟 {@code collector-server.php} 的应答：认证帧返回 ready，信封返回带同一个 id 的确认。
     * 多个测试共用这一份实现，保证它们面对的服务端行为一致。
     */
    static String respondAsCollectorServer(String message, int connectionIndex) {
        JsonObject request = JsonParser.parseString(message).getAsJsonObject();
        JsonElement action = request.get("action");
        if (action != null && "authenticate".equals(action.getAsString())) {
            return "{\"ok\":true,\"action\":\"ready\"}";
        }
        return "{\"ok\":true,\"id\":\"" + request.get("id").getAsString()
            + "\",\"type\":\"" + request.get("type").getAsString()
            + "\",\"received_at\":\"2026-01-01T00:00:00+00:00\"}";
    }

    int httpRequestCount() {
        return httpRequests.get();
    }

    /** 去重后的客户端端口数量；大于 1 说明多次请求没有复用同一条 TCP 连接。 */
    int distinctHttpClientPortCount() {
        synchronized (httpClientPorts) {
            return Set.copyOf(httpClientPorts).size();
        }
    }

    List<String> httpRequestHeads() {
        synchronized (httpRequestHeads) {
            return new ArrayList<>(httpRequestHeads);
        }
    }

    int websocketConnectionCount() {
        return websocketConnections.get();
    }

    List<String> collectorMessages() {
        synchronized (collectorMessages) {
            return new ArrayList<>(collectorMessages);
        }
    }

    void setHttpStatus(int status) {
        this.httpStatus = status;
    }

    @Override
    public void close() {
        running = false;
        try {
            serverSocket.close();
        } catch (IOException ignored) {
            // 关闭监听失败不影响测试收尾。
        }
        workers.shutdownNow();
    }

    private void acceptLoop() {
        while (running) {
            try {
                Socket connection = serverSocket.accept();
                workers.execute(() -> handleConnection(connection));
            } catch (IOException stopped) {
                return;
            }
        }
    }

    private void handleConnection(Socket socket) {
        try (Socket connection = socket) {
            connection.setSoTimeout(15000);
            InputStream input = connection.getInputStream();
            OutputStream output = connection.getOutputStream();
            while (running) {
                String head = readHead(input);
                if (head.isEmpty()) {
                    return;
                }
                if (isWebSocketUpgrade(head)) {
                    performWebSocketHandshake(head, output);
                    int index = websocketConnections.incrementAndGet();
                    serveWebSocket(input, output, index);
                    return;
                }
                input.readNBytes(contentLength(head));
                httpRequests.incrementAndGet();
                synchronized (httpClientPorts) {
                    httpClientPorts.add(connection.getPort());
                }
                httpRequestHeads.add(head);
                writeHttpResponse(output);
            }
        } catch (IOException ignored) {
            // 测试收尾时连接被关闭属于正常现象。
        }
    }

    private void serveWebSocket(InputStream input, OutputStream output, int connectionIndex) throws IOException {
        while (running) {
            Frame frame = readFrame(input);
            if (frame == null) {
                return;
            }
            if (frame.opcode() == CLOSE_OPCODE) {
                writeFrame(output, CLOSE_OPCODE, new byte[0]);
                return;
            }
            if (frame.opcode() != TEXT_OPCODE || frame.text().isEmpty()) {
                continue;
            }
            collectorMessages.add(frame.text());
            String response = handler.respond(frame.text(), connectionIndex);
            if (response != null) {
                writeFrame(output, TEXT_OPCODE, response.getBytes(StandardCharsets.UTF_8));
            }
        }
    }

    private void writeHttpResponse(OutputStream output) throws IOException {
        byte[] body = ("{\"ok\":true,\"type\":\"status\",\"received_at\":\"2026-01-01T00:00:00+00:00\","
            + "\"history_stored\":false,\"history_transport\":\"wss\"}").getBytes(StandardCharsets.UTF_8);
        String head = "HTTP/1.1 " + httpStatus + " " + reasonPhrase(httpStatus) + "\r\n"
            + "Content-Type: application/json\r\n"
            + "Content-Length: " + body.length + "\r\n"
            + "Connection: keep-alive\r\n\r\n";
        output.write(head.getBytes(StandardCharsets.US_ASCII));
        output.write(body);
        output.flush();
    }

    private static String reasonPhrase(int status) {
        if (status == 200) {
            return "OK";
        }
        return status == 503 ? "Service Unavailable" : "Status";
    }

    private static String readHead(InputStream input) throws IOException {
        ByteArrayOutputStream buffer = new ByteArrayOutputStream();
        int value;
        while ((value = input.read()) >= 0) {
            buffer.write(value);
            byte[] bytes = buffer.toByteArray();
            int length = bytes.length;
            if (length >= 4
                && bytes[length - 4] == '\r' && bytes[length - 3] == '\n'
                && bytes[length - 2] == '\r' && bytes[length - 1] == '\n') {
                break;
            }
        }
        return buffer.toString(StandardCharsets.ISO_8859_1);
    }

    private static int contentLength(String head) {
        String value = headerValue(head, "content-length");
        if (value == null || value.isEmpty()) {
            return 0;
        }
        return Integer.parseInt(value.trim());
    }

    private static boolean isWebSocketUpgrade(String head) {
        String upgrade = headerValue(head, "upgrade");
        return upgrade != null && upgrade.toLowerCase(Locale.ROOT).contains("websocket");
    }

    private static String headerValue(String head, String name) {
        String prefix = name.toLowerCase(Locale.ROOT) + ":";
        for (String line : head.split("\r\n")) {
            if (line.toLowerCase(Locale.ROOT).startsWith(prefix)) {
                return line.substring(prefix.length()).trim();
            }
        }
        return null;
    }

    private static void performWebSocketHandshake(String head, OutputStream output) throws IOException {
        String key = headerValue(head, "sec-websocket-key");
        if (key == null || key.isEmpty()) {
            throw new IOException("WebSocket 握手缺少 Sec-WebSocket-Key");
        }
        String accept;
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-1");
            accept = Base64.getEncoder().encodeToString(
                digest.digest((key + WEBSOCKET_GUID).getBytes(StandardCharsets.US_ASCII)));
        } catch (NoSuchAlgorithmException unavailable) {
            throw new IOException("当前 JDK 不支持 SHA-1", unavailable);
        }
        String response = "HTTP/1.1 101 Switching Protocols\r\n"
            + "Upgrade: websocket\r\n"
            + "Connection: Upgrade\r\n"
            + "Sec-WebSocket-Accept: " + accept + "\r\n\r\n";
        output.write(response.getBytes(StandardCharsets.US_ASCII));
        output.flush();
    }

    private static Frame readFrame(InputStream input) throws IOException {
        int first = input.read();
        if (first < 0) {
            return null;
        }
        int second = input.read();
        if (second < 0) {
            return null;
        }
        int opcode = first & 0x0F;
        boolean masked = (second & 0x80) != 0;
        long length = second & 0x7F;
        if (length == 126L) {
            length = ((long) input.read() << 8) | input.read();
        } else if (length == 127L) {
            length = 0L;
            for (int index = 0; index < 8; index++) {
                length = (length << 8) | input.read();
            }
        }
        if (length < 0L || length > MAX_FRAME_LENGTH) {
            throw new IOException("测试服务端收到超出预期的 WebSocket 帧长度：" + length);
        }
        byte[] mask = masked ? input.readNBytes(4) : new byte[0];
        byte[] payload = input.readNBytes((int) length);
        if (masked && mask.length == 4) {
            for (int index = 0; index < payload.length; index++) {
                payload[index] = (byte) (payload[index] ^ mask[index % 4]);
            }
        }
        return new Frame(opcode, new String(payload, StandardCharsets.UTF_8));
    }

    private static void writeFrame(OutputStream output, int opcode, byte[] payload) throws IOException {
        output.write(0x80 | opcode);
        if (payload.length < 126) {
            output.write(payload.length);
        } else if (payload.length < 65536) {
            output.write(126);
            output.write((payload.length >> 8) & 0xFF);
            output.write(payload.length & 0xFF);
        } else {
            output.write(127);
            for (int shift = 56; shift >= 0; shift -= 8) {
                output.write((payload.length >> shift) & 0xFF);
            }
        }
        output.write(payload);
        output.flush();
    }

    private record Frame(int opcode, String text) {
    }
}
