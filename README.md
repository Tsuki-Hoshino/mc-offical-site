# MC Official Site Collector

这是一个面向 Minecraft Java 版服务器的官网与运行状态采集器。仓库同时提供 PHP 网站、Fabric/Forge 模组、Paper 插件、部署模板和网站内的管理功能。

生产环境的采集器通过 WSS 主动上报到网站，网站同时保留 HTTPS POST 作为兼容回退，并提供以下页面：

- 实时状态：在线玩家、假人、MSPT/TPS、Java 进程资源、主机资源、磁盘和网络指标。
- 玩家统计：按玩家查看服务端统计数据。
- 配方：浏览内置配方、搜索物品；编辑器支持站点管理员维护自定义配方。
- 附魔计算：计算铁砧合并顺序、经验等级和惩罚等级。
- 经纬度：登记机器位置、维度换算、说明和附件。
- 计划表：解析 Litematica 投影，统计材料并协作认领。
- 终端：超级管理员可通过 MCSManager Daemon 查看实例、控制台输出和实例文件。

仓库只包含源码、静态资源和配置模板，不包含数据库、运行时数据、密码、同步令牌、MCSManager 密钥或生产证书。

## 目录

- [一、运行链路](#一运行链路)
- [二、仓库目录](#二仓库目录)
- [三、运行要求](#三运行要求)
- [四、部署网站](#四部署网站)
- [五、配置采集器](#五配置采集器)
- [六、构建和安装采集器](#六构建和安装采集器)
- [七、网站模块和权限](#七网站模块和权限)
- [八、接口和数据](#八接口和数据)
- [九、实时服务和终端](#九实时服务和终端)
- [十、验证和维护](#十验证和维护)
- [十一、开发说明](#十一开发说明)
- [许可证](#许可证)

## 一、运行链路

### 采集器到网站

1. Fabric 模组、Forge 模组或 Paper 插件加载共用的 `collector/core` 逻辑。
2. 采集器每秒执行一次采样任务，根据配置准备状态和玩家统计数据。
3. 生产采集器连接 `wss://SITE_DOMAIN/ws/collector`，先发送 `{"action":"authenticate","token":"..."}` 完成同步令牌认证，再发送带 `id`、`type`、`payload` 的 JSON 信封。
4. `website/ws/collector-server.php` 校验信封后保存最新快照到 `website/data/inbox/<type>.json.php`，并向采集端返回确认消息。
5. `status` 信封会立即广播到 `wss://SITE_DOMAIN/ws/status`，同时转发给历史写入工作进程，由 `history_store_status()` 异步写入 `server_metrics`。
6. WSS 暂时不可用时，采集器可回退到 `POST /api/push.php?type=status` 或 `POST /api/push.php?type=stats`。回退接口只保存最新快照，不直接写入历史表，并返回 `history_stored: false`。
7. 状态页优先订阅 `wss://当前域名/ws/status`；连接失败后才轮询 `GET /api/latest.php?type=status`。两条读取路径都使用 `no-store`，避免缓存实时数据。

仓库中的 `website/ws/collector-server.php` 是 WSS 接收端，`website/api/push.php` 是 HTTPS 兼容回退端。公开采集器代码中的 `HttpUploader` 对应回退上传实现；生产部署使用的 WSS 采集器应与上述信封和认证协议保持一致。

### 数据库

网站各模块共用 `website/config/database.php` 中的 PDO MySQL 连接：

- `deploy/sql/schema.sql` 提供 `server_metrics` 历史指标表。
- 统一认证首次连接时创建 `users`、`audit_logs`。
- 站点设置、配方、经纬度和计划表按模块初始化自己的表。

数据库账号需要拥有应用运行所需的建表、读写和事务权限。生产环境应使用专用账号，不要让网站使用 MySQL `root`。

## 二、仓库目录

| 路径 | 用途 |
| --- | --- |
| `website/` | PHP 页面、HTTP API、JavaScript、样式和静态资源；部署时将其作为网站根目录。 |
| `website/api/` | 采集器 Push、最新状态、历史指标和历史清理接口。 |
| `website/config/*.template` | 私密数据库和同步配置模板；实际配置文件不能提交。 |
| `website/统一认证/` | 共享 Session、用户、角色、CSRF、登录限流和审计。 |
| `website/终端/` | MCSManager Daemon 客户端、终端页面和实例文件管理。 |
| `website/计划表/` | Litematica 解析、材料统计、项目成员和认领。 |
| `website/经纬度/` | 机器登记、坐标换算和附件管理。 |
| `website/配方/` | 静态配方索引、数据库配方和搜索接口。 |
| `website/状态/` | 实时状态页和历史图表页。 |
| `collector/` | Gradle 多模块采集器源码。 |
| `collector/core/` | Fabric、Forge、Paper 共用的 Java 采样、快照和 HTTPS 兼容上传逻辑。 |
| `collector/fabric-*` | 各 Minecraft 版本的 Fabric 模组。 |
| `collector/forge-*` | 各 Minecraft 版本的 Forge 模组。 |
| `collector/paper/` | 通用 Paper/Spigot 插件。 |
| `config/mc-official-site.toml.template` | Minecraft 服务端采集器配置模板。 |
| `deploy/` | Nginx、Apache、cron 和历史表结构模板。 |
| `scripts/publish-website.ps1` | Windows 上打包并通过 SSH/SCP/rsync 发布 `website/` 的脚本。 |
| `.github/workflows/build.yml` | 按模块运行核心测试并构建采集器 JAR。 |

`website/data/` 是运行时目录，不在仓库中提供。它用于保存 Push 快照、认证状态、上传附件、终端状态和配方缩略图，并被 `.gitignore` 排除。

## 三、运行要求

### 网站服务器

- Nginx 或 Apache，并启用 HTTPS。
- PHP 8.1 或更高版本；生产环境建议使用 PHP 8.3/8.4。
- PHP 扩展：PDO MySQL、JSON、OpenSSL、cURL、mbstring、zlib。
- MySQL 8 或兼容版本。
- PHP-FPM 用户可以写入 `website/data/`，但不应拥有整个网站目录的写权限。

### Minecraft 服务器

- Fabric、Forge 或 Paper 服务端。
- 与 Minecraft 版本和加载器匹配的采集器 JAR。
- 能够访问网站的 HTTPS/WSS 地址；生产上报优先使用 WSS，HTTPS POST 仅作回退。

### 构建环境

- Git。
- JDK 25。部分模块的 Java 编译目标为 8 或 21，但仓库工作流使用 JDK 25 构建全部模块。
- Windows 使用 `collector/gradlew.bat`；Linux/macOS 使用 `collector/gradlew`。

## 四、部署网站

以下步骤使用 Linux 路径变量说明部署关系。实际域名、目录、PHP-FPM 用户和证书位置由部署者提供，不应写入仓库。

### 1. 获取源码

```bash
git clone https://github.com/Tsuki-Hoshino/mc-offical-site.git
cd mc-offical-site
```

### 2. 准备网站目录和私密配置

网站根目录必须直接包含 `index.html`、`api/`、`assets/` 和 `config/`。不要将网站再套一层 `website/website/`。

```bash
cd website
cp config/database.php.template config/database.php
cp config/sync.php.template config/sync.php
mkdir -p data/inbox data/runtime data/uploads
```

`database.php` 支持以下环境变量：

| 环境变量 | 用途 |
| --- | --- |
| `MC_SITE_DB_HOST` | MySQL 主机地址 |
| `MC_SITE_DB_PORT` | MySQL 端口，默认 `3306` |
| `MC_SITE_DB_NAME` | 数据库名，默认 `mc_official_site` |
| `MC_SITE_DB_USER` | 网站数据库账号，默认 `mc_site_app` |
| `MC_SITE_DB_PASSWORD` | 数据库密码 |

`sync.php` 支持 `MC_SYNC_TOKEN`。也可以把令牌写入配置数组的 `token` 字段，但无论采用哪种方式都不能提交真实令牌。模板默认只接受 `status` 和 `stats` 两类 Push 数据，实际允许类型以服务器上的 `allowed_types` 为准。

### 3. 初始化数据库

由数据库管理员创建数据库和专用账号后，导入历史指标表：

```bash
mysql -u "$MC_SITE_DB_USER" -p "$MC_SITE_DB_NAME" < deploy/sql/schema.sql
```

统一认证、站点设置、配方、经纬度和计划表会在各模块首次连接数据库时检查并创建所需表。首次部署时应确认数据库账号具备相应权限。

### 4. 初始化第一个超级管理员

统一认证只在用户表为空、且 `website/data/bootstrap-admin.json` 存在时导入第一个超级管理员。文件需要包含 `username` 和已经按认证核心格式生成的 `password_hash`；导入成功后代码会删除该文件。

该文件只能在服务器上短暂创建并限制权限，不能放进 Git、备份包或公开下载目录。已有用户表时不会重复导入。

### 5. 配置站点和功能

页面加载 `/assets/site-config.php`，站点名称、首页标题、服务器地址、版本标签、离线判定时间、终端地址和功能开关由 `site_settings` 表提供，超级管理员可在 `/admin/` 修改。

可用功能开关及路径如下：

| 设置键 | 路径 |
| --- | --- |
| `status` | `/状态/` |
| `stats` | `/统计数据/` |
| `recipes` | `/配方/` |
| `enchant` | `/附魔计算/` |
| `machines` | `/经纬度/` |
| `plans` | `/计划表/` |

备案字段常量 `SITE_ICP_NUMBER`、`SITE_POLICE_NUMBER` 和 `SITE_POLICE_CODE` 默认为空；需要展示备案信息时只能由部署者在自己的环境中配置，不能把真实号码写入公开仓库。

### 6. 配置 Web 服务器

根据服务器类型复制并修改：

- `deploy/nginx.conf.template`
- `deploy/apache-vhost.conf.template`

将模板中的 `SITE_DOMAIN`、网站根目录、证书路径和 PHP-FPM socket 替换为实际值。必须保留以下访问限制：

- 禁止访问 `config/`、`data/`、`api/lib/`、`api/cron/` 和隐藏文件。
- PHP 只执行实际存在的 `.php` 文件。
- 网站和采集器之间始终使用 HTTPS/WSS；不得降级到明文 HTTP/WS。

配置完成后由服务器管理员执行 Web 服务器自身的配置检查和重载。项目不会在 README 验证阶段代替管理员操作生产服务。

## 五、配置采集器

采集器首次启动会在 Minecraft 服务端的 `config/` 目录生成 `mc-official-site.toml`。也可以提前复制仓库模板：

```bash
cp config/mc-official-site.toml.template "$MINECRAFT_SERVER_ROOT/config/mc-official-site.toml"
```

至少配置：

```toml
[endpoint]
site_url = "https://SITE_DOMAIN"
token = "MC_SYNC_TOKEN"
```

`site_url` 必须是网站 HTTPS 根地址，不要附加 `/api/push.php` 或 `/ws/collector`。生产 WSS 客户端使用同一主机的 `wss://SITE_DOMAIN/ws/collector`，并用 `token` 完成 WebSocket 认证；WSS 不可用时才回退到 HTTPS POST。`token` 必须与网站的 `MC_WS_TOKEN` 或 `MC_SYNC_TOKEN`（以及服务器 `sync.php` 中的令牌）完全一致。

可调整的配置分为四组：

| 配置项 | 作用 |
| --- | --- |
| `sample_interval_ticks` | 游戏刻采样间隔，程序限制为至少 1。 |
| `upload_interval_seconds` | `status` 上传间隔，程序限制为至少 1 秒。 |
| `stats_scan_interval_seconds` | 玩家统计扫描间隔，程序限制为至少 1 秒。 |
| `sync_status` | 是否上传实时状态。 |
| `sync_player_stats` | 是否扫描并上传玩家统计。 |
| `collect_network` | 是否采集网络速率和累计流量。 |
| `fake_class_keywords` | 判定假人的实体类关键词，使用逗号分隔。 |
| `fake_display_prefixes` | 判定假人显示名的前缀，使用逗号分隔。 |
| `connect_timeout_millis` | 公开 HTTPS 回退上传器建立连接的超时；生产 WSS 客户端应设置等效连接超时，程序限制为至少 1000 毫秒。 |
| `read_timeout_millis` | 公开 HTTPS 回退上传器读取响应的超时；生产 WSS 客户端应设置等效确认超时，程序限制为至少 1000 毫秒。 |

采集器检测配置文件的修改时间和大小，发现变化后会在运行中重新加载。上传失败和配置错误写入服务端 `config/mc-site-collector-errors.log`。

## 六、构建和安装采集器

### 支持的模块

Fabric：`fabric-1.14.4`、`fabric-1.16.5`、`fabric-1.18.2`、`fabric-1.20.1`、`fabric-1.21.1`、`fabric-1.21.11`、`fabric-26.1`。

Forge：`forge-1.14.4`、`forge-1.16.5`、`forge-1.18.2`、`forge-1.20.1`、`forge-1.21.1`、`forge-1.21.11`、`forge-26.1`。

Paper：`paper`，编译时使用通用 Spigot API，适用于对应 Paper/Spigot 服务端。

### 构建单个模块

Windows PowerShell：

```powershell
.\collector\gradlew.bat -p collector -PonlyProject=fabric-1.21.11 :core:test :fabric-1.21.11:build --stacktrace
```

Linux/macOS：

```bash
chmod +x collector/gradlew
./collector/gradlew -p collector -PonlyProject=fabric-1.21.11 :core:test :fabric-1.21.11:build --stacktrace
```

将命令中的模块名替换为目标模块。JAR 位于对应模块的 `build/libs/`。

### 构建全部模块

```bash
./collector/gradlew -p collector :core:test build --stacktrace
```

GitHub Actions 会按 `.github/workflows/build.yml` 的模块矩阵运行同样的测试和构建，并将每个模块的 JAR 上传为构建产物。

### 安装

- Fabric：将对应 JAR 放进服务端 `mods/`，同时安装匹配版本的 Fabric Loader 和 Fabric API。
- Forge：将对应 JAR 放进服务端 `mods/`，不要与 Fabric Loader 混用。
- Paper：将 `collector/paper/build/libs/` 中的 JAR 放进服务端 `plugins/`。

安装或替换 JAR 前应由服务器管理员完成停服、备份和启动安排。项目文档不要求通过网站终端执行这些操作。

## 七、网站模块和权限

统一认证使用唯一 Session 名 `mc_machine_session`，Cookie 设置为根路径、HttpOnly 和 SameSite=Strict，并根据 HTTPS 请求判断 Secure 属性。所有写接口都需要登录、角色、CSRF 和字段校验。

| 角色 | 权限 |
| --- | --- |
| 未登录访客 | 浏览首页、状态、统计、配方、附魔计算、经纬度和计划表公开内容。 |
| `editor` | 使用需要站内认证的业务功能，例如计划表成员协作和经纬度登记；不能进入 `/admin/` 或管理账户。 |
| `superadmin` | 站点设置、账户管理、自定义配方、终端以及所有超级管理员功能。 |

计划表的项目成员还拥有项目级角色：`owner`、`admin`、`member`。项目所有者和项目管理员负责成员维护；材料认领、完成状态和投影导入由服务端按项目权限判断。

配方编辑器支持有序和无序配方，图标由浏览器直接请求 `https://mcasset.cloud/` 资源，失败时使用文字缩写，不经过本站代理或缓存纹理。

## 八、接口和数据

### HTTP API

| 接口 | 方法 | 作用 |
| --- | --- | --- |
| `/api/push.php?type=status` | POST | WSS 不可用时的兼容回退；验证同步令牌并保存状态快照，不写历史表。 |
| `/api/push.php?type=stats` | POST | WSS 不可用时的兼容回退；验证同步令牌并保存玩家统计快照。 |
| `/api/latest.php?type=status` | GET | 返回经过公开字段过滤的最新状态。 |
| `/api/latest.php?type=stats` | GET | 返回最新玩家统计快照。 |
| `/api/history.php?metric=...` | GET | 查询 `server_metrics` 的聚合历史指标。 |
| `/配方/api/search.php?q=...` | GET | 搜索静态和数据库配方。 |

WSS 接收端限制认证动作、信封字段、允许类型和最大消息体，并返回确认或错误消息。HTTPS Push 回退接口限制请求方法、令牌、JSON 格式、允许类型和最大请求体，并返回 `Cache-Control: no-store`。公开状态响应会移除 `remote_addr`，并只保留在线玩家对应的皮肤地址。

历史接口支持 `mspt`、`process_cpu`、`process_memory`、`host_cpu`、`host_memory` 和 `network` 指标；查询失败时返回 `history_unavailable`，不会向浏览器输出数据库连接细节。

### 运行时文件

- `website/data/inbox/<type>.json.php`：最新 Push 快照，文件前缀会阻止被当作普通 JSON 直接下载。
- `website/data/runtime/`：Workerman 进程日志和终端会话状态。
- `website/data/uploads/`：经纬度模块的附件。
- `website/data/thumbnails/`：管理员上传的配方 PNG 缩略图。

这些目录必须位于 Web 根目录的受保护路径下，并且不进入 Git。

## 九、实时服务和终端

### Workerman 状态服务

`website/ws/collector-server.php` 是 CLI 进程，提供两个 WebSocket 路径：

- `/ws/status`：公开订阅最新状态和实时状态消息。
- `/ws/collector`：生产采集器使用的 WSS 上报通道。连接后先用同步令牌认证，再接收带 `id`、`type`、`payload` 的 JSON 信封；`status` 记录会广播给公开订阅者，并转发给历史写入工作进程。

该进程使用 Workerman 的 `vendor/autoload.php`。生产环境必须启动并保持该进程运行，且由 Nginx 或 Apache 将 HTTPS/WSS 请求转发到它；只有明确只使用 HTTPS 回退和页面轮询时才可以不启动。历史写入依赖同一进程的内部消息通道。

### MCSManager 终端

`/终端/` 和 `/终端/api.php` 仅允许 `superadmin`。后台保存的 `terminalUrl` 与 `terminalKey` 只在服务器端使用，永远不会下发给浏览器。终端客户端使用 Engine.IO v4 / Socket.IO v4 polling，与 Daemon 建立主会话和输出流会话。

终端具备实例查看、控制台输出、命令输入、PTY 尺寸同步以及相对路径文件管理能力。它可以启动、停止、重启或强制终止实例，也可以读写、移动、删除和上传文件；这些都是生产操作，必须由管理员自行确认影响和回滚方案。

## 十、验证和维护

### 只读验证

以下请求不会向网站或数据库写入业务数据：

```bash
curl -fsSI "$SITE_URL/"
curl -fsS "$SITE_URL/api/latest.php?type=status"
curl -fsS "$SITE_URL/api/latest.php?type=stats"
```

没有收到采集器数据时，`latest.php` 返回 `not_found` 属于正常状态。收到数据后应返回 JSON，并包含对应的 `type` 和 `payload`。历史查询只能在已配置数据库且存在历史记录时验证。

不要使用 POST 模拟采集器请求来验证生产环境，因为该接口会写入 `website/data/inbox/`；也不要使用终端启动、停止、重启、强制终止实例或发送服务器命令进行测试。

### 更新网站

网站是普通 PHP/静态文件目录，不需要前端构建。更新时保留服务器上的 `website/config/` 和 `website/data/`，再替换其余网站文件。`scripts/publish-website.ps1` 会排除私密配置和运行时数据，并依赖本机 `tar`、`ssh`、`scp` 以及远程 `rsync`。

### 历史清理

`deploy/mc-official-site-retention.cron.template` 仅供 CLI 定时任务调用，会删除 `server_metrics` 中早于一年的记录。修改路径、PHP CLI 路径和运行用户后，再由服务器管理员放入系统 cron。

### 备份

至少备份：

- `website/config/database.php` 和 `website/config/sync.php`；
- `website/data/`；
- MySQL 数据库；
- Minecraft 服务端的 `config/mc-official-site.toml`、`mods/` 或 `plugins/`。

备份文件同样包含敏感信息，必须限制读取权限，不要上传到公开仓库。

## 十一、开发说明

核心测试和构建命令：

```bash
./collector/gradlew -p collector :core:test
./collector/gradlew -p collector -PonlyProject=paper :core:test :paper:build --stacktrace
```

网站脚本为原生 JavaScript、PHP 和 HTML。修改静态资源后必须同步更新页面中的资源版本号；页面脚本需要同时兼容普通加载、PJAX 进入和销毁生命周期。

可以使用 `node --check` 检查 JavaScript 语法；PHP 语法检查应在部署环境使用 `php -l` 执行。未安装 PHP 或 JDK 25 的机器不能据此宣称网站或所有采集器构建通过。

## 许可证

根目录源码使用 MIT License，详见 [LICENSE](LICENSE)。`website/计划表/` 内保留 LiteTrack 的 GPL-3.0 许可证和 `LICENSE` 文件；使用该目录代码时还必须遵守其许可证要求。
