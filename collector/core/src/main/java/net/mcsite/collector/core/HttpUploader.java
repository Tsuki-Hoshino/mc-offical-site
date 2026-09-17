package net.mcsite.collector.core;

import com.google.gson.JsonObject;

import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.time.Duration;

/**
 * WSS 不可用时使用的 HTTPS 兼容回退上传实现。
 *
 * <p>全程复用一个 JDK 自带的 {@link HttpClient}，由它的连接池负责复用 TCP 与 TLS 连接。
 * 早期的 HttpsURLConnection 实现在排空响应体后又调用 {@code disconnect()}，会把底层 socket
 * 关闭并从 KeepAliveCache 中移除，于是每次上报都要重建一次连接；换用 HttpClient 之后不再需要
 * 这类手工干预，也不必调整 {@code http.keepAlive.timeout} 之类的全局系统属性。
 */
public final class HttpUploader implements AutoCloseable {
    public static final String TRANSPORT_NAME = "https_push";

    private final CollectorConfig config;
    private HttpClient client;
    private String clientSignature = "";

    public HttpUploader(CollectorConfig config) {
        this.config = config;
    }

    public void upload(String type, JsonObject payload) throws IOException {
        HttpClient activeClient = client();
        String siteUrl = config.siteUrl.replaceAll("/+$", "");
        HttpRequest request = HttpRequest.newBuilder(URI.create(siteUrl + "/api/push.php?type=" + type))
            .timeout(Duration.ofMillis(config.readTimeoutMillis))
            .header("Content-Type", "application/json; charset=utf-8")
            .header("X-MC-Sync-Token", config.token)
            .POST(HttpRequest.BodyPublishers.ofString(payload.toString(), StandardCharsets.UTF_8))
            .build();

        HttpResponse<Void> response;
        try {
            response = activeClient.send(request, HttpResponse.BodyHandlers.discarding());
        } catch (InterruptedException interrupted) {
            Thread.currentThread().interrupt();
            throw new IOException("HTTPS upload interrupted", interrupted);
        }

        int status = response.statusCode();
        if (status < 200 || status >= 300) {
            throw new IOException("Upload failed with HTTP " + status);
        }
    }

    /**
     * 释放连接池。停机时会走到这里；关闭之后如果还需要上报，会按当时的配置重建客户端。
     */
    @Override
    public synchronized void close() {
        HttpClient current = client;
        client = null;
        clientSignature = "";
        if (current != null) {
            current.close();
        }
    }

    /**
     * 配置会被热更新，站点地址、令牌与超时参与客户端或请求的构造，因此签名变化时重建客户端，
     * 避免继续沿用旧端点与旧超时；重建前释放旧实例持有的连接和线程。
     */
    private synchronized HttpClient client() {
        String signature = config.siteUrl + "\n" + config.token + "\n"
            + config.connectTimeoutMillis + "\n" + config.readTimeoutMillis;
        if (client != null && !signature.equals(clientSignature)) {
            client.close();
            client = null;
        }
        if (client == null) {
            client = HttpClient.newBuilder()
                .connectTimeout(Duration.ofMillis(config.connectTimeoutMillis))
                // 回退通道是每帧一次的请求-响应，用不上 HTTP/2 的多路复用；显式走 HTTP/1.1
                // 可以避免明文连接上多余的 h2c 升级协商，并让连接池按 keep-alive 稳定复用。
                .version(HttpClient.Version.HTTP_1_1)
                .build();
            clientSignature = signature;
        }
        return client;
    }
}
