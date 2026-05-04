# Software Overview and Sitemap

## What this software is
This repository is a PHP + MySQL business management and project operations system ("SpeedX BMS") intended for Hostinger-style shared hosting.

It supports:
- Multi-workspace operation (`workspaces`, workspace-scoped entities).
- Role-based users and permission controls (roles + optional granular RBAC tables).
- Client/project/phase/task lifecycle management.
- Task collaboration features (comments, attachments, tags, assignees, templates).
- Finance tracking (expenses, overhead, payments, unreceived payments, finance dashboard).
- Reporting and dashboard views for admins/managers/members/clients.
- Per-project and per-client overviews/docs/tasks pages.

## Architectural notes
- Mostly server-rendered PHP pages in repository root.
- Shared helpers and domain logic under `lib/`.
- Shared layout wrappers in `layout.php`, `layout_end.php`, and `partials/`.
- MySQL schema declared in `schema.sql`.

## High-level sitemap
### Auth and session
- `index.php`
- `login.php`
- `super_admin_login.php`
- `forgot_password.php`
- `logout.php`
- `confirmation_logout.php`
- `finally_loggedout.php`

### Dashboards
- `dashboard.php`
- `dashboard_member.php`
- `finance_dashboard.php`

### Core work management
- `clients.php`
- `client_view.php`
- `projects.php`
- `project_view.php`
- `phases_management.php`
- `phases_library.php`
- `all_tasks_admin.php`
- `my_tasks.php`
- `task_view.php`
- `task_details.php`
- `manager_submit.php`
- `manager_review.php`
- `review_completed_tasks.php`
- `submit_tasks_to_client.php`

### Project/client detail sections (legacy-style suffixed pages)
- `clients_u2013_list_view.php`
- `client_u2013_overview.php`
- `client_u2013_projects.php`
- `client_u2013_docs.php`
- `projects_u2013_list_view.php`
- `project_u2013_overview.php`
- `project_u2013_tasks.php`
- `project_u2013_docs.php`

### Task collaboration and metadata
- `task_comments.php`
- `task_activity_log.php`
- `task_attachments.php`
- `upload_task_attachment.php`
- `tags_labels.php`
- `task_templates.php`
- `task_statuses.php`
- `task_status_settings.php`
- `settings_task_statuses.php`

### Project/client configuration
- `project_types.php`
- `project_statuses.php`
- `settings_project_types.php`
- `settings_project_statuses.php`
- `custom_fields_builder.php`
- `templates.php`

### Finance
- `finance.php`
- `project_expenses.php`
- `overhead_cost.php`
- `payments_received.php`
- `unreceived_payments.php`

### Docs/search/download/utilities
- `docs.php`
- `doc_edit.php`
- `search.php`
- `download.php`
- `activity.php`
- `reports_overview.php`

### User/admin/workspace settings
- `users_management.php`
- `roles_permissions.php`
- `workspace_settings.php`
- `workspace_switching.php`
- `settings.php`
- `profile_account_settings_overview.php`
- `website_logins.php`
- `developer_performance.php`
- `salaries.php`

### Shared libraries and layout
- `lib/app.php`
- `lib/auth.php`
- `lib/db.php`
- `lib/helpers.php`
- `lib/activity.php`
- `lib/finance.php`
- `lib/module_crud.php`
- `lib/task_attachments.php`
- `layout.php`
- `layout_end.php`
- `partials/header.php`
- `partials/nav.php`
- `partials/footer.php`

## Data model highlights
Core entities include:
- `workspaces`, `roles`, `users`.
- `clients`, `projects`, `phases`, `tasks`.
- `task_assignees`, `comments`, `docs`.
- `permissions`, `role_permissions` (RBAC).
- `task_attachments`, `tags`, `task_tags`.
- `templates`, `custom_fields`, `custom_field_values`.

Most operational tables are workspace-scoped and enforce referential integrity with foreign keys.
