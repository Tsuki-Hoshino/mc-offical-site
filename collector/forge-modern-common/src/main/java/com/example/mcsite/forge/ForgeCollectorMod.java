package com.example.mcsite.forge;

import net.minecraftforge.common.MinecraftForge;
import net.minecraftforge.event.TickEvent;
import net.minecraftforge.eventbus.api.SubscribeEvent;
import net.minecraftforge.fml.common.Mod;
import com.example.mcsite.core.CollectorConfig;
import com.example.mcsite.core.CollectorService;
import com.example.mcsite.core.ReflectiveServerAccess;

import java.nio.file.Path;
import java.nio.file.Paths;

@Mod(ForgeCollectorMod.MOD_ID)
public final class ForgeCollectorMod {
    public static final String MOD_ID = "mc_official_site_collector";

    private final Path gameDirectory = Paths.get(".").toAbsolutePath().normalize();
    private CollectorConfig config;
    private CollectorService collector;
    private Object server;
    private int ticks;

    public ForgeCollectorMod() {
        MinecraftForge.EVENT_BUS.register(this);
    }

    @SubscribeEvent
    public void onServerTickStart(TickEvent.ServerTickEvent.Pre event) {
        startIfNeeded();
        if (collector != null && ticks % config.sampleIntervalTicks == 0) {
            collector.ticks().startTick();
        }
    }

    @SubscribeEvent
    public void onServerTickEnd(TickEvent.ServerTickEvent.Post event) {
        if (collector == null) {
            return;
        }
        if (ticks % config.sampleIntervalTicks == 0) {
            collector.ticks().endTick();
        }
        ticks++;
        if (ticks % 20 == 0) {
            collector.updateSnapshot(ReflectiveServerAccess.snapshot(server, config, gameDirectory));
        }
    }

    private void startIfNeeded() {
        Object current = ReflectiveServerAccess.currentForgeServer();
        if (current == null || current == server) {
            return;
        }
        if (collector != null) {
            collector.stop();
        }
        server = current;
        try {
            Path configDirectory = gameDirectory.resolve("config");
            config = CollectorConfig.load(configDirectory.resolve("mc-official-site.toml"));
            collector = new CollectorService(gameDirectory, configDirectory, config);
            collector.updateSnapshot(ReflectiveServerAccess.snapshot(server, config, gameDirectory));
            collector.start();
        } catch (Exception error) {
            collector = null;
        }
    }
}