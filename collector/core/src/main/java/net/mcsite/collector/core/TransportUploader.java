package net.mcsite.collector.core;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.io.IOException;

/**
 * 上报通道的选择策略：优先 WSS，失败后回退 HTTPS Push。
 *
 * <p>WSS 失败通常意味着对端不可达或协议不匹配，这类问题不会在一秒内自愈。如果每一帧都重新
 * 尝试握手，主流程会被反复的连接超时拖慢，因此失败后暂停一段时间再重试，其余帧直接走 HTTPS。
 */
public final class TransportUploader implements AutoCloseable {
    private final CollectorConfig config;
    private final WsUploader wsUploader;
    private final HttpUploader httpUploader;
    private volatile long wssRetryAtMillis;

    public TransportUploader(CollectorConfig config) {
        this.config = config;
        this.wsUploader = new WsUploader(config);
        this.httpUploader = new HttpUploader(config);
    }

    /**
     * 上报一帧，返回本帧实际使用的通道名，并把它写入 {@code payload.telemetry.transport}，
     * 使网站展示的来源与真实通道一致。
     */
    public String upload(String type, JsonObject payload) throws IOException {
        IOException wssFailure = null;
        if (config.wssEnabled && System.currentTimeMillis() >= wssRetryAtMillis) {
            try {
                markTransport(payload, WsUploader.TRANSPORT_NAME);
                wsUploader.upload(type, payload);
                return WsUploader.TRANSPORT_NAME;
            } catch (IOException failure) {
                wssFailure = failure;
                wssRetryAtMillis = System.currentTimeMillis() + config.wssRetryIntervalSeconds * 1000L;
                wsUploader.close();
            }
        }

        markTransport(payload, HttpUploader.TRANSPORT_NAME);
        try {
            httpUploader.upload(type, payload);
        } catch (IOException failure) {
            if (wssFailure != null) {
                // 两个通道都失败时把 WSS 的原因一并带出，否则日志里只剩回退通道的报错。
                failure.addSuppressed(wssFailure);
            }
            throw failure;
        }
        return HttpUploader.TRANSPORT_NAME;
    }

    @Override
    public void close() {
        wsUploader.close();
        httpUploader.close();
    }

    private static void markTransport(JsonObject payload, String transport) {
        JsonElement telemetry = payload.get("telemetry");
        if (telemetry != null && telemetry.isJsonObject()) {
            telemetry.getAsJsonObject().addProperty("transport", transport);
        }
    }
}
