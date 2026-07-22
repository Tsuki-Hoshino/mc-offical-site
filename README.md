# MC Official Site Collector

这是一个给 Minecraft 服务器配套用的小站和采集器。采集器装在服务端里，负责收集 MSPT、Java 进程资源、主机资源、网络和玩家统计，然后把数据推到网站；网页只读网站自己的 PHP API，不会去查 MCSM。

## 目录

`website/` 是网站本体，`collector/` 是 Fabric、Forge 和 Paper 的采集器源码。`config/mc-official-site.toml.example` 是配置模板，真实配置不要提交。

## 采集器配置

把模板复制成服务器上的 `mc-official-site.toml`，填好网站地址和 token：

```toml
[endpoint]
site_url = "https://mc.example.com/"
token = ""

[timing]
sample_interval_ticks = 1
upload_interval_seconds = 1
stats_scan_interval_seconds = 1
```

每 Tick 的采样会先在内存里聚合，上传按 `upload_interval_seconds` 执行。网站判断离线也只看采集器最后一次 HTTPS Push。

## 构建采集器

JAR 不放在 Git 里。推送 `main` 或 `v*` 标签后，GitHub Actions 会跑测试并构建矩阵里的模块，JAR 在对应的 workflow artifact 中下载。

本地只需要时可以跑核心测试：

```text
collector/gradlew -p collector :core:test
```

## 网站部署

先在本地 PHP 环境检查 `website/`，确认无误后再上传公开网页文件。生产服务器上的数据库、token、历史数据和 HTTPS 配置都留在服务器，不属于这个仓库。

## 许可证

MIT License，见 `LICENSE`。