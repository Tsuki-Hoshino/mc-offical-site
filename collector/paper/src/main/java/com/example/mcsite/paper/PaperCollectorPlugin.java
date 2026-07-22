package com.example.mcsite.paper;

import org.bukkit.Bukkit;
import org.bukkit.entity.Player;
import org.bukkit.event.Event;
import org.bukkit.event.EventPriority;
import org.bukkit.event.Listener;
import org.bukkit.plugin.EventExecutor;
import org.bukkit.plugin.java.JavaPlugin;
import org.bukkit.scheduler.BukkitTask;
import com.example.mcsite.core.CollectorConfig;
import com.example.mcsite.core.CollectorService;
import com.example.mcsite.core.PlayerInfo;
import com.example.mcsite.core.ServerSnapshot;

import java.lang.reflect.Method;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public final class PaperCollectorPlugin extends JavaPlugin {
    private final Listener tickListener = new Listener() { };
    private CollectorConfig config;
    private CollectorService collector;
    private BukkitTask fallbackTask;
    private int ticks;

    @Override
    public void onEnable() {
        try {
            Path gameDirectory = Paths.get(".").toAbsolutePath().normalize();
            Path configDirectory = gameDirectory.resolve("config");
            config = CollectorConfig.load(configDirectory.resolve("mc-official-site.toml"));
            collector = new CollectorService(gameDirectory, configDirectory, config);
            collector.updateSnapshot(snapshot());
            collector.start();
            if (!registerPaperTickEvents()) {
                fallbackTask = Bukkit.getScheduler().runTaskTimer(this, new Runnable() {
                    @Override
                    public void run() {
                        Double average = averageTickTime();
                        if (average != null) {
                            collector.ticks().recordMilliseconds(average.doubleValue());
                        }
                        afterTick();
                    }
                }, 1L, 1L);
            }
        } catch (Exception error) {
            getLogger().severe("采集器启动失败: " + error.getMessage());
            collector = null;
        }
    }

    @Override
    public void onDisable() {
        if (fallbackTask != null) {
            fallbackTask.cancel();
            fallbackTask = null;
        }
        if (collector != null) {
            collector.stop();
            collector = null;
        }
    }

    private boolean registerPaperTickEvents() {
        String[][] candidates = {
            {
                "com.destroystokyo.paper.event.server.ServerTickStartEvent",
                "com.destroystokyo.paper.event.server.ServerTickEndEvent"
            },
            {
                "io.papermc.paper.event.server.ServerTickStartEvent",
                "io.papermc.paper.event.server.ServerTickEndEvent"
            }
        };
        for (String[] pair : candidates) {
            try {
                registerTickEvent(pair[0], true);
                registerTickEvent(pair[1], false);
                return true;
            } catch (Exception ignored) {
            }
        }
        getLogger().warning("当前 Paper 未提供 Tick Start/End 事件，MSPT 将回退到服务端平均 Tick 时间。");
        return false;
    }

    @SuppressWarnings("unchecked")
    private void registerTickEvent(String className, final boolean start) throws Exception {
        Class<?> rawClass = Class.forName(className);
        if (!Event.class.isAssignableFrom(rawClass)) {
            throw new IllegalArgumentException(className);
        }
        Bukkit.getPluginManager().registerEvent(
            (Class<? extends Event>) rawClass,
            tickListener,
            EventPriority.MONITOR,
            new EventExecutor() {
                @Override
                public void execute(Listener listener, Event event) {
                    if (collector == null) {
                        return;
                    }
                    if (start) {
                        if (ticks % config.sampleIntervalTicks == 0) {
                            collector.ticks().startTick();
                        }
                    } else {
                        if (ticks % config.sampleIntervalTicks == 0) {
                            collector.ticks().endTick();
                        }
                        afterTick();
                    }
                }
            },
            this,
            true
        );
    }

    private void afterTick() {
        ticks++;
        if (ticks % 20 == 0 && collector != null) {
            collector.updateSnapshot(snapshot());
        }
    }

    private ServerSnapshot snapshot() {
        List<PlayerInfo> players = new ArrayList<PlayerInfo>();
        for (Player player : Bukkit.getOnlinePlayers()) {
            String display = player.getDisplayName();
            players.add(new PlayerInfo(player.getUniqueId(), player.getName(), isFake(player, display)));
        }
        return new ServerSnapshot(true, Bukkit.getMotd(), players);
    }

    private boolean isFake(Player player, String displayName) {
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

    private Double averageTickTime() {
        try {
            Method method = Bukkit.getServer().getClass().getMethod("getAverageTickTime");
            return ((Number) method.invoke(Bukkit.getServer())).doubleValue();
        } catch (Exception ignored) {
            return null;
        }
    }
}