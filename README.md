# 通用后台框架

基于 Laravel 和 Vue 的后台项目模板。

本地安装并启动 lerd 后，在仓库根目录运行 `./bin/setup-lerd`。输入主域名后，脚本会初始化 Laravel 和数据库，并把 Vue 开发服务器启动在独立容器里。默认前端地址为 `http://web.backend.test/`，API 域名为 `http://api.backend.test`。本地初始账号和密码均为 `admin`。

- [项目说明](docs/README.md) · 管理员 API、权限、审计和本地开发
- [框架路线图](docs/ADMIN_FRAMEWORK_ROADMAP.md) · 当前能力、开发优先级和验收标准
- [菜单管理实施方案](docs/MENU_MANAGEMENT_PLAN.md) · 菜单、管理员认证和权限联动
- 前端模板说明 · [简体中文](docs/web/README.zh-CN.md) / [English](docs/web/README.md)
- 前端更新日志 · [简体中文](docs/web/CHANGELOG.zh-CN.md) / [English](docs/web/CHANGELOG.md)
