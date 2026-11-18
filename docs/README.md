<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## 后台框架

项目定位、当前能力、迭代优先级和验收标准见 [通用后台框架路线图](ADMIN_FRAMEWORK_ROADMAP.md)。

## API

API routes are defined in `routes/api.php` and use Laravel Sanctum authentication. The `GET /api/user` endpoint returns the authenticated user when supplied with a valid `Authorization: Bearer <token>` header. Missing or invalid tokens return a JSON response with HTTP status `401`.

Run `php artisan migrate` to create the personal access token table. Run `php artisan test --compact tests/Feature/ApiAuthenticationTest.php` to verify API authentication.

## Roles and permissions

Role and permission management uses `spatie/laravel-permission`. The `User` model includes `HasRoles`, allowing role assignment with `assignRole()` and authorization through Laravel's `can()` checks. Package settings are in `config/permission.php`.

Use `App\Models\AdminRole` and `App\Models\AdminPermission` for role and permission records. These extend Spatie's models and use `admin_roles` and `admin_permissions`. Their pivot tables are `admin_model_has_roles`, `admin_model_has_permissions`, and `admin_role_has_permissions`. Existing installations are upgraded with a data-preserving table rename migration.

Run `php artisan migrate` to create the permission tables. Use `php artisan permission:cache-reset` when a manual permission change requires clearing the package cache.

## Administrator accounts

Administrator records use `App\Models\AdminUser` and the `admin_users` table. Usernames are unique; nickname and password are required. Avatar, email, mobile, and signature are optional. Status is a boolean (`1` enabled, `0` disabled) and defaults to enabled.

Roles use the existing Spatie relationships and the `web` permission guard. Passwords are hashed automatically and excluded from serialized responses. `AdminUserSeeder` provisions the enabled `admin` account with initial password `admin` and the `super-admin` role; repeated runs do not reset a changed password. Migrations do not create administrator accounts. This public weak credential is for local initialization only and must be changed immediately in deployed environments.

### Administrator API and menu setup

The administrator API uses `/api/v1/admin`: `POST /auth/login` accepts `userName` and `password`, `GET /me` returns the current profile and effective button permissions, and `POST /auth/logout` revokes only the current token. Send `Authorization: Bearer <token>` on protected requests. Tokens expire after seven days; configure `ADMIN_TOKEN_DAYS` on the backend to change this. There is no refresh token. Sessions and ordinary `User` tokens cannot authenticate administrator requests. Disabling an administrator through the `AdminUser` model revokes their tokens.

After reviewing the database connection, run `php artisan migrate --no-interaction` and `php artisan db:seed --no-interaction` on a fresh installation to initialize the management menus and the default `admin / admin` super administrator. To initialize only `system.menu` and its access/view/create/update/delete permissions, run `php artisan db:seed --class=AdminMenuSeeder --no-interaction`; this menu seeder is repeatable and creates no accounts or role assignments. Ordinary roles require both page access and the relevant operation permission.

Menu endpoints are `GET/POST /menus`, `GET/PATCH/DELETE /menus/{id}`, `GET /menus/permission-tree`, `GET /menus/{id}/deletion-impact`, and `GET /me/menus`. Deletion requires a JSON body containing `{"confirmed":true}` and rejects nodes with children. Page creation defaults to four basic buttons; send `"buttons":[]` for none, or select from `view/create/update/delete`. Node names and types are immutable. Custom button names must use the parent menu's prefix, for example `goods.export`.

Controlled business routes must use `admin.auth` and `admin.permission:<full permission name>` (for example `admin.permission:goods.update`). This checks the page access permission, operation permission and every ancestor's enabled state, including for `super-admin`. Raw Spatie `can()` checks do not include menu branch status. Menu writes are transactional; permission caches are cleared again after commit. Route configuration does not register backend business endpoints.

Run `php artisan test --compact tests/Feature/AdminAuthenticationTest.php tests/Feature/AdminMenuTest.php tests/Feature/AdminUserTest.php tests/Feature/ApiAuthenticationTest.php tests/Feature/PermissionTableMigrationTest.php` to verify these boundaries. Tests migrate an isolated SQLite in-memory database; do not run development migrations merely to execute tests.

PHPUnit forcibly overrides inherited server environment values and the shared `Tests\TestCase` refuses to boot tests against any database other than SQLite `:memory:`. Keep both protections when changing local test configuration.

### Administrator and role group management

Run `php artisan migrate --no-interaction` and `php artisan db:seed --class=AdminManagementMenuSeeder --no-interaction` to initialize the `system` directory (权限管理), containing 菜单管理, 管理员管理, 角色组管理, and 管理员日志管理. The seeder ensures `system.menu`, `system.admin`, and `system.role` exist with access/view/create/update/delete permissions, and `system.log` with access/view permissions, without creating accounts or granting ordinary roles new permissions. Existing pages are moved under the directory while preserving their node IDs, permission IDs, configuration, enabled states, and role assignments.

Administrator endpoints are `GET/POST /api/v1/admin/admins` and `GET/PATCH/DELETE /api/v1/admin/admins/{id}`. Lists accept `page`, `per_page`, `keyword`, and `status`. Creation requires a username, nickname, password of at least eight characters, and role IDs. On update, an omitted or blank password preserves the existing password; changing it revokes that administrator's tokens. Disabling accounts and deleting them also revoke their tokens. The current administrator cannot delete/disable themselves or remove their own roles, and management writes preserve the last enabled super administrator.

The administrator list displays saved avatars. The create and edit dialogs preview a selected image and upload it on save. For an avatar upload, send a multipart `POST` with the `avatar` file in JPEG, PNG, WebP, or GIF format, up to 2 MiB. Include `_method=PATCH` for updates. Updates that omit `avatar` preserve the saved image. The public disk serves avatars through the link created by `php artisan storage:link --no-interaction`.

Role group endpoints are `GET/POST /api/v1/admin/roles`, `GET/PATCH/DELETE /api/v1/admin/roles/{id}`, `GET /api/v1/admin/roles/options`, and `GET /api/v1/admin/roles/permission-tree`. Groups have a stable code (`name`), display name, parent, status, remark, and `permission_ids`. Deletions require `{"confirmed":true}` and reject groups with children or assigned members. The reserved `super-admin` group is read-only through these APIs. Ordinary administrators may manage only strict descendants of their own enabled groups and accounts whose roles and direct permissions are all within their management scope; their own groups, peers, ancestors, and unrelated groups are not writable.

Child allocations cannot exceed the parent allocation or the acting administrator's permissions. Saving selected buttons also grants their page access permission; removing previously granted page access clears that page's buttons. Parent permission reductions synchronously prune all descendants in the same transaction and never automatically expand them later. Disabled roles or ancestors contribute no effective permissions. Role groups express delegation limits; membership does not inherit an ancestor's permissions or its super administrator designation. All management APIs still enforce the page/operation and menu-branch checks.

The role and administrator pages use these real APIs. Their selection trees submit permission IDs rather than menu IDs. Run `php artisan test --compact tests/Feature/AdminRoleManagementTest.php tests/Feature/AdminAccountManagementTest.php` for hierarchy, scope, account protections, permission dependency, and transactional rollback coverage.

### Administrator audit logs

Apply migrations before serving the updated administrator API; successful logins and management writes require the `admin_logs` table. Re-run `php artisan db:seed --class=AdminManagementMenuSeeder --no-interaction` to add the log page to existing installations. Ordinary readers require both `system.log.access` and `system.log.view`; super administrators see the new page automatically while its menu branch is enabled.

`GET /api/v1/admin/logs` returns paginated, filtered records, and `GET /api/v1/admin/logs/{id}` includes sanitized before/after changes. Supported filters are `page`, `per_page` (maximum 100), `keyword`, `actor_id`, `module`, `action`, `success`, `status_code`, `ip_address`, `date_from`, and `date_to`. Datetimes include a timezone. Ordinary readers see their own events and actors currently in their management scope; inaccessible details return 404. Super administrators also see deleted actor snapshots and unverified login attempts. The API and page are read-only.

Login/logout and administrator, role, and menu writes are recorded on success and failure; ordinary reads are excluded. Successful writes and their audit records share a transaction, and login rolls back token issuance if its audit cannot be saved. Logout revokes its current token even if audit persistence fails. Failures have no field diffs or raw exception messages. Email/mobile values are masked, password changes use only a marker, and request bodies, response bodies, credentials, tokens, cookies, and authorization headers are never copied into the audit record. Login 429 records are deduplicated per normalized username, IP, and minute; other failed logins remain individually recorded.

`ADMIN_LOG_RETENTION_DAYS` defaults to 90. `php artisan admin-logs:prune --no-interaction` deletes older records in bounded batches; the same command runs daily through Laravel's scheduler. Start `php artisan schedule:work` locally or configure the standard `schedule:run` cron in production. There is no browser deletion endpoint. Run `php artisan test --compact tests/Feature/AdminLogTest.php tests/Feature/AdminLogPruneTest.php` for recording, privacy, rollback, read scope, filters, and retention checks against the isolated test database.

## Attachment management

Run `php artisan migrate --no-interaction`, `php artisan db:seed --class=AttachmentManagementMenuSeeder --no-interaction`, and `php artisan storage:link --no-interaction`. The seeder is repeatable, creates the top-level 附件管理 page and its `system.attachment` access/view/create/update/delete permissions, and does not create accounts or role grants.

The shared attachment library supports paginated filters, image preview, download, title/category editing, single or multi-file upload, and single or batch deletion through `GET/POST /api/v1/admin/attachments`, `GET/PATCH/DELETE /api/v1/admin/attachments/{id}`, and `GET /api/v1/admin/attachments/{id}/temporary-url`. Batch actions call the single-file endpoints and report per-file results. Deletes require `{"confirmed":true}` and directly remove both the physical file and database record; business references are not tracked and deletion cannot be undone.

Uploads default to the private `local` disk. Only images may be explicitly public; public images and all rich-text editor uploads use the `public` disk and long-term URLs. Private previews and all downloads use five-minute signed file-stream URLs with inline or attachment disposition. `POST /api/common/upload/wangeditor` retains the editor response contract while sharing the same attachment service. Object keys use generated UUIDs, URLs are not stored, SHA-256 is metadata rather than deduplication, and only title/category are editable.

Allowed formats are JPEG/PNG/WebP/GIF, PDF, DOC/DOCX, XLS/XLSX, TXT, and ZIP. Both the original extension and actual MIME type are validated; SVG, executables, corrupt images, and oversized files are rejected. Defaults are 50 MiB per file (`ATTACHMENT_MAX_SIZE`, bytes), 30 uploads per minute per administrator (`ATTACHMENT_UPLOADS_PER_MINUTE`), and 300-second signed URLs (`ATTACHMENT_TEMPORARY_URL_TTL`). Frontend batches contain at most ten files sent separately. PHP and reverse-proxy request-body limits must also allow the configured size. Attachment writes use administrator auditing; upload rollback removes newly stored files, while deletion retries tolerate an already missing file.

Run `php artisan test --compact tests/Feature/AttachmentManagementTest.php tests/Feature/AdminLogTest.php` to verify upload, storage, permissions, filtering, signed URLs, direct deletion, editor upload, auditing, throttling, and failure cleanup.

## Frontend

The Art Design Pro frontend is in `web/`. Copy `web/.env.example` to `web/.env`, set a local `VITE_LOCK_ENCRYPT_KEY`, then run `pnpm install` and `pnpm dev` from `web/`. Local environment files, dependencies, build output, and SQLite databases are excluded from Git.

Use backend permission mode (`VITE_ACCESS_MODE=backend`). Development requests go through the Vite `/api` proxy; set `VITE_API_PROXY_URL` to your Laravel service. Production uses same-origin `/api` URLs, so serve/proxy that prefix to Laravel. Restart Vite after environment changes and rebuild for production. Start Laravel with `php artisan serve`, then run `pnpm dev` in `web/`. Log in with an explicitly provisioned administrator. An enabled account with no menu permissions remains logged in and sees “暂无可访问菜单”. Administrator, hierarchical role group, and menu management pages are connected to their real APIs. The menu page maintains directories, pages, and button permissions through `/api/v1/admin/menus`, while navigation still uses `/api/v1/admin/me/menus`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
