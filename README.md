# MC Official Site Collector

这是一个给 Minecraft Java 版服务器使用的“官网 + 运行状态采集器”项目。

它把采集器安装在 Minecraft 服务器里，定期读取服务器运行数据并通过 HTTPS 发到网站；网站再把数据展示给访客。项目不依赖 MCSM 等第三方面板，网站只读取自己收到的数据。

适合的使用场景：你有一台 Minecraft 服务器，希望拥有一个可以放在自己域名下的官网，显示在线人数、假人数量、MSPT、CPU、内存、网络流量、玩家统计和历史图表。

> 本文默认读者几乎没有 Linux、PHP 或 Git 经验。文中的 `mc.example.com`、`/var/www/mc-official-site`、数据库密码等都是示例，请替换成你自己的值。

## 目录

- [一、它是怎样工作的](#一它是怎样工作的)
- [二、项目目录](#二项目目录)
- [三、开始前要准备什么](#三开始前要准备什么)
- [四、先在电脑上获取项目](#四先在电脑上获取项目)
- [五、部署网站](#五部署网站)
- [六、配置 Minecraft 采集器](#六配置-minecraft-采集器)
- [七、构建采集器 JAR](#七构建采集器-jar)
- [八、安装到服务器](#八安装到服务器)
- [九、配置项详解](#九配置项详解)
- [十、验证是否成功](#十验证是否成功)
- [十一、日常维护与更新](#十一日常维护与更新)
- [十二、常见问题](#十二常见问题)
- [十三、安全建议](#十三安全建议)
- [十四、开发者说明](#十四开发者说明)
- [许可证](#许可证)

## 一、它是怎样工作的

完整链路如下：

1. Minecraft 服务器加载采集器（Fabric 模组、Forge 模组或 Paper 插件）。
2. 采集器读取游戏刻、玩家、Java 进程、主机和网络信息。
3. 采集器每隔一段时间向 `https://你的域名/api/push.php` 发送 HTTPS POST，并在请求头中携带同步令牌。
4. PHP API 验证令牌，把最新状态写入 `website/data/inbox/`；状态数据同时写入 MySQL 的 `server_metrics` 表。
5. 浏览器访问网站时，页面通过 `api/latest.php` 和 `api/history.php` 获取数据。

采集器离线后，网站会在超过 `offlineAfterSeconds`（默认 15 秒）没有新数据时显示“服务器离线”。

## 二、项目目录

| 路径 | 用途 |
| --- | --- |
| `website/` | 要发布到 PHP 网站服务器的网页、图片、JavaScript 和 API。 |
| `website/config/*.example` | 网站私密配置模板，复制后去掉 `.example` 才会生效。 |
| `website/api/` | 接收采集器数据、读取最新数据和历史数据的 PHP 接口。 |
| `website/data/` | 运行时数据目录，不能提交到 Git，也不能让访客直接下载。 |
| `collector/` | 采集器的 Gradle 多模块源码。 |
| `collector/core/` | Fabric、Forge、Paper 共用的采集逻辑。 |
| `collector/fabric-*` | 对应 Minecraft 版本的 Fabric 模组。 |
| `collector/forge-*` | 对应 Minecraft 版本的 Forge 模组。 |
| `collector/paper/` | Paper/Spigot 插件。 |
| `config/mc-official-site.toml.example` | Minecraft 服务器端采集器配置模板。 |
| `deploy/` | Nginx、Apache、数据库建表和定期清理示例。 |
| `scripts/publish-website.ps1` | Windows 上通过 SSH 发布网站的脚本。 |
| `.github/workflows/build.yml` | GitHub Actions 自动测试并构建所有采集器。 |

## 三、开始前要准备什么

### 网站服务器

- 一个域名，例如 `mc.example.com`，并把 DNS 的 A/AAAA 记录指向网站服务器。
- Linux 服务器（Ubuntu、Debian 等均可）。
- Nginx 或 Apache。
- PHP 8.3 及 PHP-FPM，至少启用 `pdo_mysql`、`json`、`openssl`。
- MySQL 8/MariaDB 等兼容 MySQL 的数据库。
- 有效的 HTTPS 证书。采集器只接受 `https://` 地址；可以使用 Let's Encrypt。
- 能够写入网站目录下的 `website/data/`，以及连接数据库。

### Minecraft 服务器

- 已经可以正常启动的 Fabric、Forge 或 Paper 服务器。
- 与服务器版本完全匹配的采集器 JAR。不要把 1.20.1 的模组放到 1.21.11。
- 服务器进程可以访问你的 HTTPS 网站。

### 构建环境（只在自己编译 JAR 时需要）

- Git。
- JDK 25（GitHub Actions 使用 Temurin JDK 25）。较新的 Minecraft 模组源码会限制到 Java 21，但用 JDK 25 构建最稳妥。
- Windows 可以直接使用仓库内的 `collector/gradlew.bat`；Linux/macOS 使用 `collector/gradlew`。

## 四、先在电脑上获取项目

如果你不会 Git，可以在 GitHub 项目页面点击 **Code → Download ZIP**，解压后得到项目文件夹。

如果已经安装 Git，在 PowerShell 或终端执行：

```bash
git clone <项目仓库地址>
cd mc-offical-site
```

后续命令都默认在项目根目录执行，也就是能看到 `website`、`collector` 和 `README.md` 的目录。

## 五、部署网站

下面是最小可用流程。域名、Linux 用户名和路径请按自己的服务器修改。

### 1. 创建数据库和账号

登录 MySQL 后创建一个专用数据库账号（不要使用 root 给网站连接）：

```sql
CREATE DATABASE mc_official_site CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mc_official_site'@'localhost' IDENTIFIED BY '请换成很长的数据库密码';
GRANT ALL PRIVILEGES ON mc_official_site.* TO 'mc_official_site'@'localhost';
FLUSH PRIVILEGES;
```

导入项目提供的表结构：

```bash
mysql -u mc_official_site -p mc_official_site < deploy/sql/schema.sql
```

这会创建 `server_metrics` 表，用于保存历史指标。若暂时不需要历史图表，网站仍可显示最新状态，但建议直接完成数据库配置。

### 2. 上传网站文件

把整个 `website/` 目录上传到服务器，例如：

```text
/var/www/mc-official-site/website/
```

网站根目录必须直接包含 `index.html`、`api/`、`assets/` 和 `config/`，不要多套一层 `website/website/`。

### 3. 创建网站私密配置

在服务器执行：

```bash
cd /var/www/mc-official-site/website
cp config/database.php.example config/database.php
cp config/sync.php.example config/sync.php
mkdir -p data/inbox
```

编辑 `config/database.php`，填写上一步的数据库信息。然后生成一个随机同步令牌，并同时写入网站和 Minecraft 服务器配置。令牌不需要是固定格式，例如可以在 Linux 上生成：

```bash
openssl rand -hex 32
```

把令牌填入 `config/sync.php` 的 `token`：

```php
'token' => '这里填随机生成的长字符串',
```

也可以不把令牌写在文件里，改用环境变量 `MC_SYNC_TOKEN`。两种方式选一种即可；不要把真实令牌提交到 Git 或发到公开聊天中。

### 4. 修改站点名称和服务器地址

编辑 `website/assets/site-config.js`。最常用的设置都集中在文件开头：

```javascript
window.MCSiteConfig = {
    siteName: '示例服务器',
    serverName: '生存服务器',
    serverAddress: 'mc.example.com',
    editionLabel: 'MINECRAFT JAVA EDITION 1.21.11 FABRIC',
    icpNumber: '',
    policeNumber: '',
    policeCode: '',
    offlineAfterSeconds: 15
};
```

`siteName` 是页头和页脚名称，`serverName` 是首页标题，`serverAddress` 是玩家要复制的进服地址。没有备案信息时保持空字符串即可。`offlineAfterSeconds` 表示网站多久收不到新状态后显示离线，通常应大于 `upload_interval_seconds` 的数倍。

若要更换图标和背景，分别替换 `website/assets/server-icon.png` 和 `website/assets/site-background.jpg`，尽量保持原文件名，避免还要修改页面引用。

### 5. 配置 Nginx 或 Apache

复制 `deploy/nginx.conf.example` 或 `deploy/apache-vhost.conf.example`，把示例域名、网站根目录、证书路径和 PHP-FPM socket 改成实际值。

Nginx 示例默认使用：

```text
root /var/www/mc-official-site/website;
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
```

不同系统的 PHP-FPM socket 可能是 `php8.2-fpm.sock` 或其他名称，请用服务器实际文件名替换。配置完成后检查并重载：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

配置中的安全规则会阻止访问 `config/`、`data/`、`api/lib/` 和 `api/cron/`。这些规则不要删除。

### 6. 设置文件权限

让 PHP-FPM 用户可以写入数据目录，但不要让整个网站目录都可写：

```bash
sudo chown -R root:root /var/www/mc-official-site/website
sudo chown -R www-data:www-data /var/www/mc-official-site/website/data
sudo chown root:www-data /var/www/mc-official-site/website/config/*.php
sudo chmod 750 /var/www/mc-official-site/website/data
sudo chmod 640 /var/www/mc-official-site/website/config/*.php
```

如果你的系统 PHP-FPM 用户不是 `www-data`，请替换为实际用户。

### 7. （推荐）定期删除旧历史

项目提供的 `deploy/mc-official-site-retention.cron.example` 会每天删除一年以前的记录。把路径改成实际路径后，复制到 `/etc/cron.d/mc-official-site-retention`，并确认 PHP CLI 路径正确：

```text
17 3 * * * www-data /usr/bin/php /var/www/mc-official-site/website/api/cron/prune-history.php >> /var/log/mc-official-site-retention.log 2>&1
```

## 六、配置 Minecraft 采集器

采集器首次启动时会自动在服务器的 `config/` 目录生成 `mc-official-site.toml`。也可以提前复制模板：

```bash
cp config/mc-official-site.toml.example <你的服务器>/config/mc-official-site.toml
```

必须至少修改：

```toml
[endpoint]
site_url = "https://mc.example.com"
token = "与网站完全相同的令牌"
```

`site_url` 必须以 `https://` 开头，不要填写 `/api/push.php`，程序会自动拼接接口地址。令牌多一个空格或少一个字符都会导致 401 未授权。

修改配置后通常不需要重新编译 JAR。采集器会自动检测配置文件变化并重新加载；若没有生效，重启 Minecraft 服务器即可。

## 七、构建采集器 JAR

### 只构建一个版本（推荐）

Windows PowerShell：

```powershell
.\collector\gradlew.bat -p collector -PonlyProject=fabric-1.21.11 :core:test :fabric-1.21.11:build --stacktrace
```

Linux/macOS：

```bash
chmod +x collector/gradlew
./collector/gradlew -p collector -PonlyProject=fabric-1.21.11 :core:test :fabric-1.21.11:build --stacktrace
```

把命令中的 `fabric-1.21.11` 换成你需要的模块。生成的 JAR 在：

```text
collector/fabric-1.21.11/build/libs/
```

### 构建全部版本

```bash
./collector/gradlew -p collector :core:test build --stacktrace
```

全部版本构建时间较长，并且需要下载各版本 Minecraft、Fabric、Forge 或 Paper 依赖。网络不稳定时建议只构建一个模块，或直接下载 GitHub Actions 的构建产物。

### 支持的模块

Fabric：`1.14.4`、`1.16.5`、`1.18.2`、`1.20.1`、`1.21.1`、`1.21.11`、`26.1`。

Forge：`1.14.4`、`1.16.5`、`1.18.2`、`1.20.1`、`1.21.1`、`1.21.11`、`26.1`。

Paper：`paper`（通用 Paper/Spigot 插件，插件声明的最低 API 版本为 1.14）。

## 八、安装到服务器

- Fabric：将对应 JAR 放入服务器的 `mods/`，同时确保安装匹配版本的 Fabric Loader 和 Fabric API。
- Forge：将对应 JAR 放入服务器的 `mods/`，不要混用 Fabric Loader。
- Paper：将 `collector/paper/build/libs/` 中的 JAR 放入 `plugins/`。

安装前先停止服务器，复制一份存档和配置作为备份，再启动服务器。首次启动后检查 `config/mc-official-site.toml` 是否存在。

采集器错误日志通常位于：

```text
服务器目录/config/mc-site-collector-errors.log
```

## 九、配置项详解

| 配置项 | 作用 | 建议 |
| --- | --- | --- |
| `site_url` | 网站 HTTPS 根地址 | 必须是 `https://`，不要带 API 路径 |
| `token` | API 鉴权令牌 | 与网站完全一致，使用长随机字符串 |
| `sample_interval_ticks` | 每隔多少游戏刻采样一次 | `1` 最及时；性能紧张可调大 |
| `upload_interval_seconds` | 状态上传间隔 | `1` 表示每秒一次；过小会增加请求量 |
| `stats_scan_interval_seconds` | 玩家统计扫描间隔 | 通常 `10` 或 `30` 已足够 |
| `sync_status` | 是否上传实时状态 | `true` 才会更新在线状态和历史指标 |
| `sync_player_stats` | 是否上传玩家统计 | 不需要时可设为 `false` |
| `collect_network` | 是否采集网络速率 | 不需要时可设为 `false` |
| `fake_class_keywords` | 判定假人的实体类关键词，逗号分隔 | 默认 `fake,carpet` |
| `fake_display_prefixes` | 判定假人显示名的前缀，逗号分隔 | 中文服可按实际名称修改 |
| `connect_timeout_millis` | 建立 HTTPS 连接超时时间 | 默认 5000 毫秒 |
| `read_timeout_millis` | 读取 API 响应超时时间 | 默认 8000 毫秒 |

程序会把时间间隔限制为至少 1；网络超时限制为至少 1000 毫秒。

## 十、验证是否成功

1. 浏览器打开 `https://你的域名/`，确认首页能正常显示。
2. 用浏览器打开 `https://你的域名/api/latest.php?type=status`。未收到数据时会返回 `not_found`，收到数据后应返回 JSON 且包含 `ok: true`。
3. 检查 `website/data/inbox/status.json.php` 是否生成。该文件以 `.php` 结尾是为了防止被当作普通 JSON 直接下载。
4. 查看 Minecraft 控制台和 `config/mc-site-collector-errors.log`，确认没有 401、404、500 或 SSL 错误。
5. 等待几分钟后打开状态页和历史图表；历史图表依赖 MySQL 配置和 `server_metrics` 表。

可以用下面的命令模拟一次推送（请替换域名和令牌）：

```bash
curl -i -X POST "https://mc.example.com/api/push.php?type=status" \
  -H "Content-Type: application/json" \
  -H "X-MC-Sync-Token: 你的令牌" \
  -d '{"generated_at":"2026-01-01T00:00:00Z","runtime":{"mspt":10}}'
```

正确时会返回 HTTP 200 和 `"ok":true`。这个测试会写入一条状态数据，生产环境测试后可删除对应历史记录。

## 十一、日常维护与更新

### 更新网页

先备份 `website/config/` 和 `website/data/`，再上传新的 `website/` 内容。`scripts/publish-website.ps1` 会自动排除私密配置和数据目录，并在远程服务器创建部署前备份：

```powershell
.\scripts\publish-website.ps1 `
  -HostName mc.example.com `
  -UserName root `
  -RemoteRoot /var/www/mc-official-site `
  -IdentityFile C:\Users\你的用户名\.ssh\id_ed25519
```

该脚本需要本机有 `tar`、`ssh`、`scp`，远程服务器有 `rsync`。不使用脚本时，手动上传 `website/` 也可以。

### 更新采集器

1. 确认 Minecraft 版本和加载器没有变化。
2. 下载或构建同名的新 JAR。
3. 停服并备份 `mods/`、`plugins/`、`config/mc-official-site.toml`。
4. 替换旧 JAR 后启动服务器。
5. 查看日志并访问 API 验证数据。

### 备份

至少备份以下内容：

- `website/config/database.php`
- `website/config/sync.php`
- `website/data/`
- MySQL 数据库（例如 `mysqldump` 导出）
- Minecraft 服务器的 `config/mc-official-site.toml`

## 十二、常见问题

**网页能打开，但一直显示离线**：检查采集器是否安装了正确版本、`site_url` 是否为 HTTPS、令牌是否一致，并从服务器执行 `curl -I https://你的域名/` 测试网络和证书。

**API 返回 `401 unauthorized`**：请求没有带令牌或令牌拼写不一致。采集器使用 `X-MC-Sync-Token` 请求头。

**API 返回 `503 token_not_configured`**：网站的 `config/sync.php` 仍是示例值，或环境变量 `MC_SYNC_TOKEN` 为空。

**API 返回 `500` 或历史图表不可用**：检查 `config/database.php`、数据库账号权限、`pdo_mysql` 扩展和 `server_metrics` 表；同时查看 PHP-FPM 错误日志。

**数据目录写入失败**：确认 PHP-FPM 用户对 `website/data/` 有写权限，且父目录存在。

**构建时提示 Java 版本不对**：安装 JDK 25，并确认 `java -version` 和 `JAVA_HOME` 指向它。不要只安装 JRE，构建需要 JDK。

**Fabric/Forge 启动崩溃**：确认 Minecraft、Loader、API 和采集器模块的版本完全匹配。Forge 和 Fabric 的 JAR 不能互换。

**历史数据越来越大**：启用 cron 清理任务；默认脚本删除一年以前的记录。需要更短保留期时，请自行修改 SQL 的时间间隔。

## 十三、安全建议

- 全站强制 HTTPS，不要为了“先试试”改成 HTTP；采集器会拒绝 HTTP。
- 同步令牌使用至少 32 字节随机值，网站和服务器分别限制文件权限。
- 不要把 `website/config/`、`website/data/`、数据库密码或真实令牌提交到 Git。
- 保留 Nginx/Apache 中禁止访问私密目录的规则。
- 数据库使用专用账号，不要让 PHP 使用 MySQL root。
- 定期更新操作系统、PHP、Web 服务器和 Minecraft Loader，并保留可恢复的备份。
- 生产环境关闭 PHP 详细错误输出，把错误写入日志。

## 十四、开发者说明

运行核心单元测试：

```bash
./collector/gradlew -p collector :core:test
```

只检查并构建指定模块：

```bash
./collector/gradlew -p collector -PonlyProject=paper :core:test :paper:build --stacktrace
```

GitHub Actions 工作流会在推送到 `main`、创建 `v*` 标签、Pull Request 或手动触发时，使用 JDK 25 构建全部 Fabric、Forge 和 Paper 模块。构建完成后，可在 Actions 运行页面的 Artifacts 中下载对应 JAR。

网站是普通文件目录，开发时可以用 PHP 内置服务器临时查看页面：

```bash
php -S 127.0.0.1:8080 -t website
```

但真实推送、HTTPS、PHP-FPM 和 MySQL 仍应在正式服务器上验证。

## 许可证

本项目使用 MIT License，详见 [LICENSE](LICENSE)。
