package com.example.mcsite.core;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

public final class ServerSnapshot {
    public static final ServerSnapshot OFFLINE = new ServerSnapshot(false, "", Collections.<PlayerInfo>emptyList());

    public final boolean online;
    public final String motd;
    public final List<PlayerInfo> players;

    public ServerSnapshot(boolean online, String motd, List<PlayerInfo> players) {
        this.online = online;
        this.motd = motd == null ? "" : motd;
        this.players = Collections.unmodifiableList(new ArrayList<PlayerInfo>(players));
    }
}
