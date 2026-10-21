/**
 * Where a job's own screen is. The API only returns kinds the role can see, so the
 * kind's own permission is already held; an application's screens also need the
 * `application` permission, and without it the row is shown without a link.
 */
export function operationHref(operation, { canOpenApplications }) {
  const subject = operation.subject ?? {};
  const appId = subject.type === "application" ? subject.id : null;
  const app = (section) => (appId && canOpenApplications ? `/applications/${appId}/${section}` : null);

  switch (operation.kind) {
    case "backup":
    case "restore":
      return subject.type === "application" ? app("backups") : "/backups";
    case "clone":
      return app("clone");
    case "staging":
      return app("staging");
    case "deployment":
      return app("deployment");
    case "database_export":
    case "database_import":
      return subject.id ? `/databases/${subject.id}` : "/databases";
    case "runtime_install":
      return /^node/i.test(subject.name ?? "") ? "/node" : "/php";
    case "sync":
      return "/sync";
    case "security_update":
      return "/settings/maintenance";
    case "panel_update":
      return "/admin/panel-update";
    default:
      return null;
  }
}
