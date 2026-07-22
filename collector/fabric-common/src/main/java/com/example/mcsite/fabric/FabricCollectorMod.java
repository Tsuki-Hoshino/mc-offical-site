package com.example.mcsite.fabric;

import com.mojang.authlib.GameProfile;
import net.fabricmc.api.ModInitializer;
import net.fabricmc.fabric.api.event.lifecycle.v1.ServerLifecycleEvents;
import net.fabricmc.fabric.api.event.lifecycle.v1.ServerTickEvents;
import net.fabricmc.loader.api.FabricLoader;
import net.minecraft.server.MinecraftServer;
import net.minecraft.server.level.ServerPlayer;
import com.example.mcsite.core.CollectorConfig;
import com.example.mcsite.core.CollectorService;
import com.example.mcsite.core.PlayerInfo;
import com.example.mcsite.core.ServerSnapshot;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class FabricCollectorMod implements ModInitializer {
    private CollectorService collector;
    private CollectorConfig config;
    private int tickCounter;

    @Override
    public void onInitialize() {
        ServerLifecycleEvents.SERVER_STARTING.register(this::start);
        ServerLifecycleEvents.SERVER_STOPPING.register(server -> stop());
        ServerTickEvents.START_SERVER_TICK.register(server -> {
            if (collector != null && tickCounter % config.sampleIntervalTicks == 0) {
                collector.ticks().startTick();
            }
        });
        ServerTickEvents.END_SERVER_TICK.register(server -> {
            if (collector == null) {
                return;
            }
            if (tickCounter % config.sampleIntervalTicks == 0) {
                collector.ticks().endTick();
            }
            tickCounter++;
            if (tickCounter % 20 == 0) {
                collector.updateSnapshot(snapshot(server));
            }
        });
    }

    private void start(MinecraftServer server) {
        try {
            Path gameDirectory = FabricLoader.getInstance().getGameDir();
            Path configDirectory = FabricLoader.getInstance().getConfigDir();
            config = CollectorConfig.load(configDirectory.resolve("mc-official-site.toml"));
            collector = new CollectorService(gameDirectory, configDirectory, config);
            collector.updateSnapshot(snapshot(server));
            collector.start();
        } catch (Exception ignored) {
            collector = null;
        }
    }

    private void stop() {
        if (collector != null) {
            collector.stop();
            collector = null;
        }
    }

    private ServerSnapshot snapshot(MinecraftServer server) {
        List<PlayerInfo> players = new ArrayList<PlayerInfo>();
        for (ServerPlayer player : server.getPlayerList().getPlayers()) {
            GameProfile profile = player.getGameProfile();
            String display = player.getDisplayName().getString();
            players.add(new PlayerInfo(profile.getId(), profile.getName(), isFake(player, display)));
        }
        return new ServerSnapshot(true, readMotd(), players);
    }

    private boolean isFake(ServerPlayer player, String displayName) {
        String className = player.getClass().getName().toLowerCase(Locale.ROOT);
        for (String keyword : config.fakeClassKeywords.toLowerCase(Locale.ROOT).split(",")) {
            if (!keyword.trim().isEmpty() && className.contains(keyword.trim())) {
                return true;
            }
        }
        for (String prefix : config.fakeDisplayPrefixes.split(",")) {
            if (!prefix.trim().isEmpty() && displayName.startsWith(prefix.trim())) {
                return true;
            }
        }
        return false;
    }

    private String readMotd() {
        try {
            for (String line : Files.readAllLines(FabricLoader.getInstance().getGameDir().resolve("server.properties"), StandardCharsets.UTF_8)) {
                if (line.startsWith("motd=")) {
                    return line.substring(5).replace("\\n", "\n");
                }
            }
        } catch (Exception ignored) {
        }
        return "";
    }
}
