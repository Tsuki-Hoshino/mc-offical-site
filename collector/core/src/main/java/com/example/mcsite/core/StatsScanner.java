package com.example.mcsite.core;

import com.google.gson.Gson;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import javax.net.ssl.HttpsURLConnection;
import java.io.Reader;
import java.io.Writer;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.nio.file.DirectoryStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Instant;
import java.util.ArrayList;
import java.util.Base64;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;

public final class StatsScanner {
    private static final String[] DISTANCE_KEYS = {
        "walk_one_cm", "sprint_one_cm", "crouch_one_cm", "swim_one_cm",
        "walk_under_water_one_cm", "walk_on_water_one_cm", "boat_one_cm",
        "minecart_one_cm", "horse_one_cm", "aviate_one_cm", "fly_one_cm"
    };

    private final Path gameDirectory;
    private final Path cachePath;
    private final Map<String, String> skinUrls = new HashMap<String, String>();
    private long previousSignature = Long.MIN_VALUE;

    public StatsScanner(Path gameDirectory, Path configDirectory) {
        this.gameDirectory = gameDirectory;
        this.cachePath = configDirectory.resolve("mc-site-skins.json");
        loadSkinCache();
    }

    public synchronized JsonObject scanIfChanged() throws Exception {
        Path statsDirectory = statsDirectory();
        Path usercache = gameDirectory.resolve("usercache.json");
        List<Path> files = jsonFiles(statsDirectory);
        long signature = signature(files, usercache);
        if (signature == previousSignature) {
            return null;
        }
        previousSignature = signature;
        Map<String, User> users = readUsers(usercache);
        JsonArray items = new JsonArray();
        List<JsonObject> sorted = new ArrayList<JsonObject>();
        for (Path file : files) {
            JsonObject source = readObject(file);
            if (source == null || !source.has("stats") || !source.get("stats").isJsonObject()) {
                continue;
            }
            String uuid = file.getFileName().toString().replaceFirst("\\.json$", "");
            User user = users.get(uuid.toLowerCase());
            JsonObject categories = source.getAsJsonObject("stats");
            JsonObject custom = object(categories, "minecraft:custom");
            JsonObject mined = object(categories, "minecraft:mined");
            JsonObject item = new JsonObject();
            item.addProperty("name", user == null ? "Unknown" : user.name);
            item.addProperty("play_time", value(custom, "minecraft:play_time"));
            item.addProperty("deaths", value(custom, "minecraft:deaths"));
            item.addProperty("mob_kills", value(custom, "minecraft:mob_kills"));
            item.addProperty("player_kills", value(custom, "minecraft:player_kills"));
            item.addProperty("jumps", value(custom, "minecraft:jump"));
            item.addProperty("mined_blocks", sum(mined));
            item.addProperty("distance_cm", distance(custom));
            item.addProperty("last_modified_at", Instant.ofEpochMilli(Files.getLastModifiedTime(file).toMillis()).toString());
            if (source.has("DataVersion")) {
                item.add("data_version", source.get("DataVersion"));
            } else {
                item.add("data_version", null);
            }
            String skin = user == null ? null : resolveSkin(user.uuid);
            if (skin == null) {
                item.add("skin_url", null);
            } else {
                item.addProperty("skin_url", skin);
            }
            item.add("categories", categories.deepCopy());
            sorted.add(item);
        }
        Collections.sort(sorted, new Comparator<JsonObject>() {
            @Override
            public int compare(JsonObject left, JsonObject right) {
                return Long.compare(right.get("play_time").getAsLong(), left.get("play_time").getAsLong());
            }
        });
        for (JsonObject item : sorted) {
            items.add(item);
        }
        saveSkinCache();
        JsonObject payload = new JsonObject();
        payload.addProperty("generated_at", Instant.now().toString());
        payload.addProperty("count", items.size());
        payload.add("items", items);
        return payload;
    }

    public synchronized Map<String, String> skinsByName() {
        Map<String, String> result = new HashMap<String, String>();
        try {
            for (User user : readUsers(gameDirectory.resolve("usercache.json")).values()) {
                String skin = skinUrls.get(user.uuid);
                if (skin != null) {
                    result.put(user.name, skin);
                }
            }
        } catch (Exception ignored) {
        }
        return result;
    }

    private Path statsDirectory() throws Exception {
        String levelName = "world";
        Path properties = gameDirectory.resolve("server.properties");
        if (Files.exists(properties)) {
            for (String line : Files.readAllLines(properties, StandardCharsets.UTF_8)) {
                if (line.startsWith("level-name=")) {
                    levelName = line.substring("level-name=".length()).replace("\\:", ":");
                    break;
                }
            }
        }
        Path world = gameDirectory.resolve(levelName);
        Path fzStats = world.resolve("players").resolve("stats");
        return Files.isDirectory(fzStats) ? fzStats : world.resolve("stats");
    }

    private static List<Path> jsonFiles(Path directory) throws Exception {
        List<Path> result = new ArrayList<Path>();
        if (!Files.isDirectory(directory)) {
            return result;
        }
        try (DirectoryStream<Path> stream = Files.newDirectoryStream(directory, "*.json")) {
            for (Path file : stream) {
                result.add(file);
            }
        }
        Collections.sort(result);
        return result;
    }

    private static long signature(List<Path> files, Path usercache) throws Exception {
        long value = files.size();
        for (Path file : files) {
            value = value * 31L + Files.getLastModifiedTime(file).toMillis();
            value = value * 31L + Files.size(file);
        }
        if (Files.exists(usercache)) {
            value = value * 31L + Files.getLastModifiedTime(usercache).toMillis();
        }
        return value;
    }

    private static Map<String, User> readUsers(Path path) throws Exception {
        Map<String, User> result = new HashMap<String, User>();
        if (!Files.exists(path)) {
            return result;
        }
        try (Reader reader = Files.newBufferedReader(path, StandardCharsets.UTF_8)) {
            JsonArray users = new JsonParser().parse(reader).getAsJsonArray();
            for (JsonElement element : users) {
                JsonObject user = element.getAsJsonObject();
                String uuid = user.get("uuid").getAsString().toLowerCase();
                result.put(uuid, new User(uuid, user.get("name").getAsString()));
            }
        }
        return result;
    }

    private String resolveSkin(String uuid) {
        if (skinUrls.containsKey(uuid)) {
            return skinUrls.get(uuid);
        }
        HttpsURLConnection connection = null;
        try {
            connection = (HttpsURLConnection) new URL(
                "https://sessionserver.mojang.com/session/minecraft/profile/" + uuid.replace("-", "")
            ).openConnection();
            connection.setConnectTimeout(4000);
            connection.setReadTimeout(5000);
            try (Reader reader = new java.io.InputStreamReader(connection.getInputStream(), StandardCharsets.UTF_8)) {
                JsonObject profile = new JsonParser().parse(reader).getAsJsonObject();
                for (JsonElement propertyElement : profile.getAsJsonArray("properties")) {
                    JsonObject property = propertyElement.getAsJsonObject();
                    if (!"textures".equals(property.get("name").getAsString())) {
                        continue;
                    }
                    String decoded = new String(Base64.getDecoder().decode(property.get("value").getAsString()), StandardCharsets.UTF_8);
                    JsonObject textures = new JsonParser().parse(decoded).getAsJsonObject().getAsJsonObject("textures");
                    if (textures != null && textures.has("SKIN")) {
                        String url = textures.getAsJsonObject("SKIN").get("url").getAsString().replace("http:", "https:");
                        skinUrls.put(uuid, url);
                        return url;
                    }
                }
            }
        } catch (Exception ignored) {
        } finally {
            if (connection != null) {
                connection.disconnect();
            }
        }
        return null;
    }

    private void loadSkinCache() {
        if (!Files.exists(cachePath)) {
            return;
        }
        try (Reader reader = Files.newBufferedReader(cachePath, StandardCharsets.UTF_8)) {
            JsonObject object = new JsonParser().parse(reader).getAsJsonObject();
            for (Map.Entry<String, JsonElement> entry : object.entrySet()) {
                skinUrls.put(entry.getKey(), entry.getValue().getAsString());
            }
        } catch (Exception ignored) {
        }
    }

    private void saveSkinCache() {
        try {
            Files.createDirectories(cachePath.getParent());
            try (Writer writer = Files.newBufferedWriter(cachePath, StandardCharsets.UTF_8)) {
                new Gson().toJson(skinUrls, writer);
            }
        } catch (Exception ignored) {
        }
    }

    private static JsonObject readObject(Path path) {
        try (Reader reader = Files.newBufferedReader(path, StandardCharsets.UTF_8)) {
            return new JsonParser().parse(reader).getAsJsonObject();
        } catch (Exception ignored) {
            return null;
        }
    }

    private static JsonObject object(JsonObject parent, String key) {
        return parent.has(key) && parent.get(key).isJsonObject() ? parent.getAsJsonObject(key) : new JsonObject();
    }

    private static long value(JsonObject object, String key) {
        return object.has(key) ? object.get(key).getAsLong() : 0L;
    }

    private static long sum(JsonObject object) {
        long total = 0L;
        for (Map.Entry<String, JsonElement> entry : object.entrySet()) {
            total += entry.getValue().getAsLong();
        }
        return total;
    }

    private static long distance(JsonObject custom) {
        long total = 0L;
        for (String key : DISTANCE_KEYS) {
            total += value(custom, "minecraft:" + key);
        }
        return total;
    }

    private static final class User {
        final String uuid;
        final String name;

        User(String uuid, String name) {
            this.uuid = uuid;
            this.name = name;
        }
    }
}
