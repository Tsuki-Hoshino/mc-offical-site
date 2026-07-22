package com.example.mcsite.core;

import java.util.UUID;

public final class PlayerInfo {
    public final UUID uuid;
    public final String name;
    public final boolean fake;

    public PlayerInfo(UUID uuid, String name, boolean fake) {
        this.uuid = uuid;
        this.name = name;
        this.fake = fake;
    }
}
