package net.mcsite.collector.core;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.WebSocket;
import java.time.Duration;
import java.util.Set;
import java.util.concurrent.BlockingQueue;
import java.util.concurrent.CompletionStage;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicLong;

/**
 * 首选上报通道：通过 JDK 自带的 {@link WebSocket} 连接 {@code wss://<站点>/ws/collector} 上报。
 *
 * <p>协议以 {@code website/ws/collector-server.php} 为准：连接后 5 秒内必须先发认证帧，认证通过后
 * 才能发送带 {@code id}、{@code type}、{@code payload} 的信封，服务端对每个信封回一条确认或错误。
 * 协议完全落在标准库内，不引入任何第三方 WebSocket 实现，也不自行编解码 RFC 6455 帧。
 *
 * <p>TLS 证书与主机名校验沿用 JDK 默认行为，不提供关闭校验的入口。
 */
public final class WsUploader implements AutoCloseable {
    public static final String TRANSPORT_NAME = "wss";

    /** 服务端限定的信封类型，提前拦截拼错的类型名，避免把错误推到线上才发现。 */
    private static final Set<String> ALLOWED_TYPES = Set.of(
        "status", "players", "stats", "recipes", "mods", "advancements"
    );
    /** 服务端要求连接建立后 5 秒内完成认证，超时会主动断开，客户端等待窗口与之对齐。 */
    private static final long AUTHENTICATION_TIMEOUT_MILLIS = 5000L;
    /** 停机时的关闭握手不值得久等，超时后直接放弃连接。 */
    private static final long CLOSE_TIMEOUT_MILLIS = 1000L;
    private static final String COLLECTOR_PATH = "/ws/collector";

    private final CollectorConfig config;
    private final AtomicLong sequence = new AtomicLong();
    private volatile HttpClient client;
    private volatile String clientSignature = "";
    private volatile WebSocket socket;
    private volatile MessageInbox inbox;

    public WsUploader(CollectorConfig config) {
        this.config = config;
    }

    /** 发送一帧并等待确认；连接不存在时先建立连接并完成认证。 */
    public synchronized void upload(String type, JsonObject payload) throws IOException {
        if (!ALLOWED_TYPES.contains(type)) {
            throw new IllegalArgumentException("Unsupported envelope type: " + type);
        }
        connectAndAuthenticate();
        String messageId = nextMessageId(type);
        JsonObject envelope = new JsonObject();
        envelope.addProperty("id", messageId);
        envelope.addProperty("type", type);
        envelope.add("payload", payload);
        sendText(envelope.toString());
        awaitConfirmation(messageId);
    }

    @Override
    public void close() {
        WebSocket currentSocket = socket;
        socket = null;
        inbox = null;
        if (currentSocket != null) {
            try {
                currentSocket.sendClose(WebSocket.NORMAL_CLOSURE, "collector stopped")
                    .get(CLOSE_TIMEOUT_MILLIS, TimeUnit.MILLISECONDS);
            } catch (InterruptedException interrupted) {
                Thread.currentThread().interrupt();
            } catch (ExecutionException | TimeoutException ignored) {
                // 关闭握手失败无关紧要，紧接着的 abort() 会回收底层连接。
            }
            currentSocket.abort();
        }
        HttpClient currentClient = client;
        client = null;
        clientSignature = "";
        if (currentClient != null) {
            currentClient.close();
        }
    }

    private void connectAndAuthenticate() throws IOException {
        WebSocket current = socket;
        if (current != null && !current.isOutputClosed() && !current.isInputClosed() && inbox != null) {
            return;
        }
        discardSocket();
        MessageInbox freshInbox = new MessageInbox();
        WebSocket opened = open(freshInbox);
        socket = opened;
        inbox = freshInbox;
        try {
            authenticate(freshInbox);
        } catch (IOException failure) {
            discardSocket();
            throw failure;
        }
    }

    private WebSocket open(MessageInbox target) throws IOException {
        URI endpoint = endpoint();
        try {
            return client().newWebSocketBuilder()
                .connectTimeout(Duration.ofMillis(config.connectTimeoutMillis))
                .buildAsync(endpoint, target)
                .get(config.connectTimeoutMillis * 2L, TimeUnit.MILLISECONDS);
        } catch (ExecutionException failure) {
            throw new IOException("连接 " + endpoint + " 失败", failure.getCause());
        } catch (TimeoutException timeout) {
            throw new IOException("连接 " + endpoint + " 超时", timeout);
        } catch (InterruptedException interrupted) {
            Thread.currentThread().interrupt();
            throw new IOException("连接 " + endpoint + " 时被中断", interrupted);
        }
    }

    private void authenticate(MessageInbox target) throws IOException {
        JsonObject request = new JsonObject();
        request.addProperty("action", "authenticate");
        request.addProperty("token", config.token);
        sendText(request.toString());

        JsonObject response = parse(target.await(authenticationTimeoutMillis()));
        if (!isOk(response)) {
            throw new IOException("WebSocket 认证被拒绝：" + errorOf(response));
        }
    }

    private void awaitConfirmation(String messageId) throws IOException {
        MessageInbox target = inbox;
        if (target == null) {
            throw new IOException("WebSocket 连接已关闭，无法等待确认");
        }
        JsonObject response = parse(target.await(config.readTimeoutMillis));
        if (!isOk(response)) {
            throw new IOException("WebSocket 上报被拒绝：" + errorOf(response));
        }
        JsonElement confirmedId = response.get("id");
        if (confirmedId == null || !messageId.equals(confirmedId.getAsString())) {
            throw new IOException("WebSocket 确认消息的 id 与请求不一致，期望 " + messageId);
        }
    }

    private void sendText(String message) throws IOException {
        WebSocket current = socket;
        if (current == null) {
            throw new IOException("WebSocket 尚未建立连接");
        }
        try {
            current.sendText(message, true).get(config.readTimeoutMillis, TimeUnit.MILLISECONDS);
        } catch (ExecutionException failure) {
            throw new IOException("发送 WebSocket 消息失败", failure.getCause());
        } catch (TimeoutException timeout) {
            throw new IOException("发送 WebSocket 消息超时", timeout);
        } catch (InterruptedException interrupted) {
            Thread.currentThread().interrupt();
            throw new IOException("发送 WebSocket 消息时被中断", interrupted);
        }
    }

    private void discardSocket() {
        WebSocket current = socket;
        socket = null;
        inbox = null;
        if (current != null) {
            current.abort();
        }
    }

    private long authenticationTimeoutMillis() {
        return Math.min(config.readTimeoutMillis, AUTHENTICATION_TIMEOUT_MILLIS);
    }

    /**
     * 上报地址由站点根地址推导。生产配置经 {@code CollectorConfig.isConfigured()} 保证是 https，
     * 这里保留 http 分支只是为了让单元测试能用明文 ws:// 连接本机测试服务端。
     */
    private URI endpoint() throws IOException {
        String siteUrl = config.siteUrl.replaceAll("/+$", "");
        String websocketUrl;
        if (siteUrl.startsWith("https://")) {
            websocketUrl = "wss://" + siteUrl.substring("https://".length());
        } else if (siteUrl.startsWith("http://")) {
            websocketUrl = "ws://" + siteUrl.substring("http://".length());
        } else {
            throw new IOException("站点地址必须是 https:// 开头的根地址：" + siteUrl);
        }
        return URI.create(websocketUrl + COLLECTOR_PATH);
    }

    /** 消息 id 需匹配服务端校验的 {@code ^[A-Za-z0-9._:-]+$} 且不超过 80 个字符。 */
    private String nextMessageId(String type) {
        return type + "-" + System.currentTimeMillis() + "-" + sequence.incrementAndGet();
    }

    private HttpClient client() {
        String signature = config.siteUrl + "\n" + config.connectTimeoutMillis;
        HttpClient current = client;
        if (current != null && signature.equals(clientSignature)) {
            return current;
        }
        // 端点或超时已经变化，旧客户端持有的连接不再有意义，先断开再重建。
        discardSocket();
        if (current != null) {
            current.close();
        }
        HttpClient created = HttpClient.newBuilder()
            .connectTimeout(Duration.ofMillis(config.connectTimeoutMillis))
            // WebSocket 建立在 HTTP/1.1 的升级握手上，JDK 也不支持 HTTP/2 上的 WebSocket。
            .version(HttpClient.Version.HTTP_1_1)
            .build();
        client = created;
        clientSignature = signature;
        return created;
    }

    private static JsonObject parse(String message) throws IOException {
        try {
            JsonElement parsed = JsonParser.parseString(message);
            if (!parsed.isJsonObject()) {
                throw new IOException("WebSocket 响应不是 JSON 对象：" + abbreviate(message));
            }
            return parsed.getAsJsonObject();
        } catch (RuntimeException malformed) {
            throw new IOException("WebSocket 响应无法解析：" + abbreviate(message), malformed);
        }
    }

    private static boolean isOk(JsonObject response) {
        JsonElement ok = response.get("ok");
        return ok != null && ok.isJsonPrimitive() && ok.getAsBoolean();
    }

    private static String errorOf(JsonObject response) {
        JsonElement error = response.get("error");
        return error == null ? "服务端未提供错误码" : error.getAsString();
    }

    private static String abbreviate(String message) {
        return message.length() <= 200 ? message : message.substring(0, 200) + "...";
    }

    /**
     * 把 WebSocket 的异步回调转成可带超时等待的队列，让上层保持「发一帧、等一次确认」的语义。
     * 队列元素要么是文本消息，要么是表示连接已不可用的异常。
     */
    private static final class MessageInbox implements WebSocket.Listener {
        private final BlockingQueue<Object> items = new LinkedBlockingQueue<>();
        private final StringBuilder partial = new StringBuilder();

        @Override
        public void onOpen(WebSocket webSocket) {
            // 不先请求一次，后续 onText 就不会再被投递，表现为连上以后静默停摆。
            webSocket.request(1);
        }

        @Override
        public CompletionStage<?> onText(WebSocket webSocket, CharSequence data, boolean last) {
            partial.append(data);
            if (last) {
                items.add(partial.toString());
                partial.setLength(0);
            }
            // 每处理完一条就再请求一条，保持消息持续投递。
            webSocket.request(1);
            return null;
        }

        @Override
        public CompletionStage<?> onClose(WebSocket webSocket, int statusCode, String reason) {
            items.add(new IOException("WebSocket 被服务端关闭，状态码 " + statusCode + "，原因：" + reason));
            return null;
        }

        @Override
        public void onError(WebSocket webSocket, Throwable error) {
            items.add(new IOException("WebSocket 连接出错", error));
        }

        String await(long timeoutMillis) throws IOException {
            Object item;
            try {
                item = items.poll(timeoutMillis, TimeUnit.MILLISECONDS);
            } catch (InterruptedException interrupted) {
                Thread.currentThread().interrupt();
                throw new IOException("等待 WebSocket 响应时被中断", interrupted);
            }
            if (item == null) {
                throw new IOException("等待 WebSocket 响应超时（" + timeoutMillis + " 毫秒）");
            }
            if (item instanceof IOException failure) {
                throw failure;
            }
            return (String) item;
        }
    }
}
