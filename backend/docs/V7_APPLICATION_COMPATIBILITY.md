# v7 application compatibility (non-import backend)

This change is not an importer, database migration, deploy or backfill. It consumes correctly mapped records supplied later by the migration workflow. Existing running applications are not automatically rewritten.

## AI blocker
Map enabled v7 protection to `legacy_v7`, disabled to `allow_all`. This is an explicit stored policy, not detection based solely on site age or version. The normal four v8 policies and new-site default remain unchanged. The legacy catalog consists of literal tokens from the checked v7 nginx/Apache/OLS templates; the entire user-agent is exempt if it contains YandexBot, case-insensitively. Custom-edited v7 lists require separate migration review and must not be silently replaced by the standard catalog.

Frontend clients should read the policy catalog rather than assume exactly four values. Reverting this enum after a migration selected it requires mapping those records first. This code does not scan/import legacy files or select a policy by itself.

## Fail2ban
Preserve the actual v7 jail name, both configuration bodies and their associated action scope as one unit. The standard v7 server-default firewall ban differs from the current v8 site-only action. New v8 jails retain their prefix and site-only action. Do not advertise all application bans as site-only.

Stored bare identities must equal the application's safe slug. Before editing, deleting or querying bans, the manager verifies matching root-owned regular files, no hard links or symlink/writable parent directories, known negative package ownership and no other section with that identity in the configuration fragments. Missing, changed, shared, package-owned or unknown resources are refused; manually reconcile them, never overwrite SSH protection to make an import work. Saves retain existing jail-setting validation and staged config testing. Disable restores files on a failed reload before leaving the database unchanged. Resync does not rename, remove or repair custom v7 files.

No arbitrary imported action is allowed through the save API. A custom v7 action, shared filter or nonstandard file layout requires explicit migration/security review. The implementation supports the checked v7 layout with same-name jail/filter files and inherited server-default action, not every hand-edited fail2ban layout.

## 8G
The source already supports 8G on nginx, Apache and OpenLiteSpeed. Keep the current corrected ruleset, rather than downgrading it to v7's older release or adding 7G. Mapping enablement/categories belongs to migration. No legacy files are deleted by this change; coexistence with retained legacy vhost includes must be verified during migration before any rewrite.

## PHP
Map v7 `extra_conf` to `additional_directives`, `disabled_functions` to `disable_functions`; ordinary scalar settings have corresponding dedicated v8 fields. Interpret `open_basedir_status` metadata separately from the path value. Supported PHP/FPM-form additional directives can be retained and rendered for FPM/OLS. Pool structure (`pm.*`, listeners, user/group), extension loading and panel-managed security values must not be made writable merely to accept imported text.

Database storage/display is not proof that save/apply accepts every v7 directive. Unsupported process-manager values, customized additional directives and differences between DB and manually edited files must be reported by migration. No new PHP columns or weakened validation are included. The live fixture had no custom additional directives; full migrated UI and save/apply testing remains a later migration gate.
