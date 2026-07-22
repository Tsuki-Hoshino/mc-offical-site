package com.example.mcsite.core;

import java.lang.reflect.Array;
import java.lang.reflect.Method;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.List;
import java.util.Locale;
import java.util.UUID;

public final class ReflectiveServerAccess {
    private ReflectiveServerAccess() {
    }

    public static ServerSnapshot snapshot(Object server, CollectorConfig config, Path gameDirectory) {
        if (server == null) {
            return ServerSnapshot.OFFLINE;
        }
        List<PlayerInfo> result = new ArrayList<PlayerInfo>();
        Object playerList = invokeAny(server, "getPlayerList", "getPlayerManager", "func_184103_al");
        Object players = invokeAny(playerList, "getPlayers", "getPlayerList", "func_181057_v");
        for (Object player : iterable(players)) {
            Object profile = invokeAny(player, "getGameProfile", "func_146103_bH");
            UUID id = asUuid(invokeAny(profile, "getId", "id"));
            String name = asString(invokeAny(profile, "getName", "name"));
            String display = componentString(invokeAny(player, "getDisplayName", "func_145748_c_"));
            if (name.isEmpty()) {
                name = display;
            }
            if (!name.isEmpty()) {
                result.add(new PlayerInfo(id, name, isFake(player, display, config)));
            }
        }
        return new ServerSnapshot(true, readMotd(gameDirectory), result);
    }

    public static Object currentForgeServer() {
        String[] classes = {
            "net.minecraftforge.server.ServerLifecycleHooks",
            "net.minecraftforge.fml.server.ServerLifecycleHooks"
        };
        for (String className : classes) {
            try {
                Class<?> type = Class.forName(className);
                Method method = type.getMethod("getCurrentServer");
                Object server = method.invoke(null);
                if (server != null) {
                    return server;
                }
            } catch (Exception ignored) {
            }
        }
        return null;
    }

    private static String readMotd(Path gameDirectory) {
        try {
            for (String line : Files.readAllLines(gameDirectory.resolve("server.properties"), StandardCharsets.UTF_8)) {
                if (line.startsWith("motd=")) {
                    return line.substring(5).replace("\\n", "\n");
                }
            }
        } catch (Exception ignored) {
        }
        return "";
    }
    private static boolean isFake(Object player, String display, CollectorConfig config) {
        String className = player.getClass().getName().toLowerCase(Locale.ROOT);
        for (String keyword : config.fakeClassKeywords.toLowerCase(Locale.ROOT).split(",")) {
            if (!keyword.trim().isEmpty() && className.contains(keyword.trim())) {
                return true;
            }
        }
        for (String prefix : config.fakeDisplayPrefixes.split(",")) {
            if (!prefix.trim().isEmpty() && display.startsWith(prefix.trim())) {
                return true;
            }
        }
        return false;
    }

    private static Object invokeAny(Object target, String... names) {
        if (target == null) {
            return null;
        }
        for (String name : names) {
            try {
                Method method = target.getClass().getMethod(name);
                return method.invoke(target);
            } catch (Exception ignored) {
            }
        }
        return null;
    }

    private static Iterable<?> iterable(Object value) {
        if (value instanceof Iterable) {
            return (Iterable<?>) value;
        }
        if (value instanceof Collection) {
            return (Collection<?>) value;
        }
        if (value != null && value.getClass().isArray()) {
            List<Object> result = new ArrayList<Object>();
            for (int index = 0; index < Array.getLength(value); index++) {
                result.add(Array.get(value, index));
            }
            return result;
        }
        return Collections.emptyList();
    }

    private static UUID asUuid(Object value) {
        if (value instanceof UUID) {
            return (UUID) value;
        }
        try {
            return value == null ? new UUID(0L, 0L) : UUID.fromString(value.toString());
        } catch (Exception ignored) {
            return new UUID(0L, 0L);
        }
    }

    private static String asString(Object value) {
        return value == null ? "" : value.toString();
    }

    private static String componentString(Object component) {
        Object value = invokeAny(component, "getString", "getUnformattedComponentText", "func_150260_c");
        return value == null ? asString(component) : asString(value);
    }
}
