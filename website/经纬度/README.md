# 经纬度

Minecraft 机器坐标登记子站，适配站点公用 CSS、PJAX 导航与平滑滚动。

## 权限

- 未登录访客可以查看列表、详情和登记附件。
- `editor` 可以新增、编辑、删除登记并上传附件。
- `superadmin` 拥有编辑权限，并可创建、停用账户、调整角色和重置密码。
- 不提供公开注册入口；所有写操作均验证登录状态和 CSRF Token。
- 登录失败会按来源 IP 和账户限流，关键操作写入审计日志。

## 存储

- 用户、登记记录和审计日志存储在 MySQL。
- 数据库连接读取 `DATABASE_CONFIG_FILE` 指向的私有 `database.json`。
- 上传文件保存在站点目录外，通过 `file.php` 按登记记录公开读取。
- 旧版 SQLite 数据库在首次连接 MySQL 时自动迁移，迁移后保留为 `.migrated` 备份。

生产环境的私有目录为 `/www/wwwroot/mc-site-private`。Windows 本地调试使用站点根目录下的 `data`，该目录不会进入部署包。

## 兼容性

代码同时通过 PHP 7.3 与 PHP 8.4 语法检查。运行环境需要 PDO MySQL；迁移旧数据时还需要 PDO SQLite，图片校验使用 GD/getimagesize，不依赖 `finfo`。
