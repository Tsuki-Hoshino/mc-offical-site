package com.example.mcsite.core;

import com.google.gson.JsonObject;

import javax.net.ssl.HttpsURLConnection;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.URL;
import java.nio.charset.StandardCharsets;

public final class HttpUploader {
    private final CollectorConfig config;

    public HttpUploader(CollectorConfig config) {
        this.config = config;
    }

    public void upload(String type, JsonObject payload) throws IOException {
        String siteUrl = config.siteUrl.replaceAll("/+$", "");
        byte[] body = payload.toString().getBytes(StandardCharsets.UTF_8);
        HttpsURLConnection connection = (HttpsURLConnection) new URL(
            siteUrl + "/api/push.php?type=" + type
        ).openConnection();
        connection.setRequestMethod("POST");
        connection.setConnectTimeout(config.connectTimeoutMillis);
        connection.setReadTimeout(config.readTimeoutMillis);
        connection.setDoOutput(true);
        connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
        connection.setRequestProperty("X-MC-Sync-Token", config.token);
        connection.setFixedLengthStreamingMode(body.length);
        try (OutputStream output = connection.getOutputStream()) {
            output.write(body);
        }
        int status = connection.getResponseCode();
        try (InputStream input = status >= 400 ? connection.getErrorStream() : connection.getInputStream()) {
            if (input != null) {
                byte[] buffer = new byte[512];
                while (input.read(buffer) >= 0) {
                    // Drain the response so the HTTPS connection can be reused.
                }
            }
        } finally {
            connection.disconnect();
        }
        if (status < 200 || status >= 300) {
            throw new IOException("Upload failed with HTTP " + status);
        }
    }
}