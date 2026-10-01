import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { ExternalLink } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { getApplicationDatabases, getEngines, getUnattachedDatabases } from "@/lib/databases/get-databases";
import { getSiteTypes } from "@/lib/applications/get-applications";
import { siteNeedsDatabase } from "@/lib/backups/database-availability";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationIssues } from "@/lib/applications/get-applications";
import { getBackupTarget, getBackups } from "@/lib/backups/get-backups";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { getGitAccounts } from "@/lib/git/get-git";
import { gitProviderFor, providersByAccountId } from "@/lib/applications/git-provider";
import { getLatestDeployment } from "@/lib/applications/get-deployments";
import {
  getApplicationDomains,
  getApplicationCertificate,
} from "@/lib/applications/get-application-domains";
import { ProvisioningCard } from "@/components/applications/provisioning-card";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { SiteFactsCard } from "@/components/applications/site-facts-card";
import { SourceCard } from "@/components/applications/source-card";
import { ProcessCard } from "@/components/applications/process-card";
import { DomainsCard } from "@/components/applications/domains-card";
import { ProtectionCard } from "@/components/applications/protection-card";
import { AttentionStrip } from "@/components/applications/attention-strip";
import { RootLockButton } from "@/components/applications/root-lock-button";
import { getRootLock } from "@/lib/applications/get-root-lock";
import { issueItems, localKeysSupersededBy } from "@/lib/applications/issue-items";
import { BackupCard } from "@/components/applications/backup-card";
import { DatabaseCard } from "@/components/applications/database-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { MagicLoginLauncher } from "@/components/applications/magic-login-launcher";
import { CopyButton } from "@/components/ui/copy-button";
import { ApplicationStatusBadge } from "@/components/applications/application-status-badge";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const result = await getApplication(application);
  return { title: result.application?.name ?? "Application" };
}

export default async function ApplicationDetailPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    // Application-level grants live in their own catalog; deploy and domains are
    // gated there, not by the server-level `application` permission.
    getPermissions("application", id).catch(() => []),
    getTranslations("applications"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("title")} />;
  // The site is gone: land on the list and explain why on arrival.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  const canManage = can(permissions, "application", "manage");
  const canDeploy = can(appPermissions, "app_deployment", "manage", "application");
  const canSeeDeployment = can(appPermissions, "app_deployment", "view", "application");
  const canSeeDomains = can(appPermissions, "app_domain", "view", "application");
  // `/issues` is gated by `app_dashboard`, not by the `application` permission
  // that opens this page; asking without it is a 403.
  const canSeeChecks = can(appPermissions, "app_dashboard", "view", "application");
  // Already site-type gated by the API (VisiblePermissions filters by the site's
  // features), so no `site_type === "wordpress"` check here: a new site type
  // must not need a frontend change.
  const canMagicLogin = can(appPermissions, "app_magic_login", "manage", "application");
  // Filtered here rather than in the menu so permission checks stay on the server.
  const headerShortcuts = [
    can(appPermissions, "app_file", "view", "application") && "files",
    canSeeDomains && "domains",
    can(appPermissions, "app_backup", "view", "application") && "backups",
  ].filter(Boolean);
  const canSeeBackups = can(appPermissions, "app_backup", "view", "application");
  const canRunBackup = can(appPermissions, "app_backup", "manage", "application");
  const isGit = Boolean(application.repository || application.repository_url);
  // Only a serving site has domains, a certificate or a running process.
  const settled = isSettled(application);

  // Feeds the repair dialog and the provider name on the source card (the
  // payload has `git_account_id` but no provider). The read is `cache()`d and
  // hits no provider API.
  const gitAccounts =
    application.git_account_missing || application.git_account_id
      // The fetcher already unwraps the envelope: `.accounts`, not `.data.git_accounts`.
      ? await getGitAccounts().then((r) => r.accounts ?? []).catch(() => [])
      : [];

  // Databases are a SERVER-level permission; a site-level reader may hold none.
  const canSeeDatabases = can(permissions, "database", "view");
  // Attaching and creating need manage; viewers get the card without buttons.
  const canManageDatabases = can(permissions, "database", "manage");

  const [domainList, certificate, backup, backupRuns, siteDatabases, siteTypes, spareDatabases, engineList, rootLock, latestDeploy] = await Promise.all([
    settled && canSeeDomains
      ? getApplicationDomains(id)
      : Promise.resolve({ domains: [], failed: false }),
    settled && canSeeDomains
      ? getApplicationCertificate(id)
      : Promise.resolve({ certificate: null, failed: false }),
    settled && canSeeBackups
      ? getBackupTarget(id)
      : Promise.resolve({ target: null, failed: false }),
    /*
     * The target says what is scheduled, not what is running now. One row is
     * enough: `GET /backups` orders by newest id, so a run in flight is first.
     */
    settled && canSeeBackups
      ? getBackups({ application: id, per_page: 1 })
      : Promise.resolve({ backups: [] }),
    settled && canSeeDatabases
      ? getApplicationDatabases(id)
      : Promise.resolve({ databases: [], failed: false }),
    /*
     * `needs_database` is on the site TYPE, so the missing-database check needs
     * this list; the facts card also needs it to name a relabel target, hence
     * `canManage` in the gate.
     */
    settled && (canSeeDatabases || canManage)
      ? getSiteTypes().catch(() => ({ siteTypes: [] }))
      : Promise.resolve({ siteTypes: [] }),
    // For the database card's Attach picker and Create dialog.
    settled && canManageDatabases
      ? getUnattachedDatabases()
      : Promise.resolve({ databases: [] }),
    settled && canManageDatabases ? getEngines() : Promise.resolve({ engines: [] }),
    // Whether the site folder is locked against its own user.
    settled ? getRootLock(id) : Promise.resolve({ rootLock: null, failed: false }),
    // Whether a deploy is running, so Deploy now cannot queue a second one.
    settled && isGit && canSeeDeployment
      ? getLatestDeployment(id)
      : Promise.resolve({ latest: null, failed: false }),
  ]);
  const folderStatus = rootLock.rootLock?.status ?? null;

  const needsDatabase = siteNeedsDatabase(siteTypes.siteTypes, application.site_type);
  // Only when the list was read and empty, for a type that wants a database.
  const missingDatabase =
    canSeeDatabases && !siteDatabases.failed && needsDatabase && siteDatabases.databases.length === 0;

  /*
   * All values come from the application payload. Each row is gated on the
   * same grant that guards its screen, so no row links to a dead end.
   */
  const protectionItems = [
    can(appPermissions, "app_security", "view", "application") && {
      key: "password",
      label: t("protection.password"),
      on: application.basic_auth_enabled,
      state: application.basic_auth_enabled ? t("protection.on") : t("protection.off"),
      href: `/applications/${id}/security`,
    },
    can(appPermissions, "app_firewall", "view", "application") && {
      key: "firewall",
      label: t("protection.firewall"),
      on: application.waf_enabled,
      // "Watch, don't block" is on but not blocking; show the mode, not just "On".
      state: application.waf_enabled
        ? (application.waf_mode_title ?? t("protection.on"))
        : t("protection.off"),
      href: `/applications/${id}/firewall`,
    },
    can(appPermissions, "app_fail2ban", "view", "application") && {
      key: "fail2ban",
      label: t("protection.fail2ban"),
      on: application.fail2ban_enabled,
      state: application.fail2ban_enabled ? t("protection.on") : t("protection.off"),
      href: `/applications/${id}/fail2ban`,
    },
    can(appPermissions, "app_bot_blocker", "view", "application") && {
      key: "bots",
      label: t("protection.bots"),
      // A policy, not a switch: "on" means it blocks something at all.
      on: Boolean(application.ai_bot_policy) && application.ai_bot_policy !== "allow_all",
      state: application.ai_bot_policy_title ?? t("protection.off"),
      href: `/applications/${id}/bot-blocker`,
    },
    /*
     * Only a definite answer gets a row: `unknown` (no immutable flag) or a
     * failed read must not render as "Not locked".
     */
    (folderStatus === "locked" || folderStatus === "unlocked") && {
      key: "folder",
      label: t("protection.folder"),
      on: folderStatus === "locked",
      state: folderStatus === "locked" ? t("protection.locked") : t("protection.unlocked"),
      control:
        folderStatus === "unlocked" ? (
          <RootLockButton applicationId={id} path={rootLock.rootLock.path} canManage={canManage} />
        ) : null,
    },
  ].filter(Boolean);


  /*
   * `application.url` is the server's answer (http:// until a certificate is
   * serving). Do not derive the scheme from the certificate read: a failed read
   * would downgrade an https-only site. The fallback is for older APIs.
   */
  const secured = certificate.certificate?.status === "active";
  const certificateIssuing = ["pending", "issuing"].includes(certificate.certificate?.status);
  const siteUrl =
    application.url ?? `${secured ? "https" : "http"}://${application.domain}`;


  // The server's own findings come first; it checks things this page cannot see.
  // Not cached: certificate days and disk usage change on their own.
  const issues = settled && canSeeChecks
    ? await getApplicationIssues(id).catch(() => ({ issues: [], healthy: true, failed: true }))
    : { issues: [], healthy: true, failed: false };
  const superseded = localKeysSupersededBy(issues.issues);

  const attentionItems = [
    ...issueItems(issues.issues, id, (type) => t(`attention.issueAction.${type}`)),
    // The checks did not come back; never read that as "Nothing needs attention".
    issues.failed && { key: "checks", label: t("attention.checksFailed") },
    // Not while the automatic certificate is on its way; see DomainsCard.
    canSeeDomains && !domainList.failed && !certificate.failed && !secured && !certificateIssuing && {
      key: "ssl",
      label: t("attention.noCertificate"),
      action: t("attention.issueCertificate"),
      // ?tab=ssl: the Domains page opens on its Domains tab.
      href: `/applications/${id}/domains?tab=ssl`,
    },
    // Above the backup item: backups without an attached database miss the data.
    missingDatabase && {
      key: "database",
      label: t("attention.noDatabase"),
      action: t("attention.attachDatabase"),
      href: "/databases",
    },
    // A risk, not a choice: the site user can swap the folder. Only managers get
    // the link to the lock button; others see a plain line.
    folderStatus === "unlocked" && {
      key: "folder",
      label: t("attention.folderUnlocked"),
      ...(canManage ? { action: t("attention.reviewFolder"), href: "#security" } : null),
    },
    canSeeBackups && !backup.failed && !backup.target && {
      key: "backups",
      label: t("attention.noBackups"),
      action: t("attention.setUpBackups"),
      href: `/applications/${id}/backups`,
    },
    /*
     * Switched-off protections are deliberately NOT findings: every site starts
     * with them off and each is a per-site choice. The Security card below
     * already shows their state.
     */
  ]
    .filter(Boolean)
    // Drop local inferences the server's own findings already cover.
    .filter((item) => !superseded.has(item.key));

  return (
    // space-y-4: header, strip and grid read as one masthead; the grid keeps gap-6.
    <div className="space-y-4">
      <div className="rounded-xl border bg-muted/30 p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex min-w-0 items-start gap-3">
            <span className="flex size-11 shrink-0 items-center justify-center rounded-lg border bg-background">
              <SiteTypeLogo
                name={application.site_type}
                provider={gitProviderFor(application, providersByAccountId(gitAccounts))}
                size="h-6 w-6"
              />
            </span>
          <div className="min-w-0 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="min-w-0 text-2xl font-semibold tracking-tight break-words">{application.name}</h1>
              <ApplicationStatusBadge application={application} />
              <Badge variant="secondary" className="font-normal">
                {application.site_type_title ?? application.site_type}
              </Badge>
              {/* Staging copies look like the site they copy; mark them where the name is. */}
              {application.is_staging ? (
                <Badge variant="warning" className="font-normal">
                  {t("stagingBadge")}
                </Badge>
              ) : null}
            </div>
            <div className="flex items-center gap-1">
              {application.status === "active" ? (
                <a
                  href={siteUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="font-mono text-sm text-primary underline-offset-4 hover:underline"
                >
                  {application.domain}
                </a>
              ) : (
                <span className="font-mono text-sm text-muted-foreground">
                  {application.domain}
                </span>
              )}
              <CopyButton value={application.domain} />
            </div>
          </div>
          </div>

          {/* Wraps so ⋯ is not pushed off screen on a phone. */}
          <div className="flex flex-wrap items-center gap-2">
            {/* Outline: filled is reserved for what a card asks you to do. */}
            {application.status === "active" ? (
              <Button asChild variant="outline" size="sm">
                <a href={siteUrl} target="_blank" rel="noreferrer">
                  <ExternalLink className="size-4" />
                  {t("actions.visit")}
                </a>
              </Button>
            ) : null}
            {/* Beside Visit: the same act as the site's administrator. */}
            {canMagicLogin && application.status === "active" ? (
              <MagicLoginLauncher appId={id} />
            ) : null}
            <ApplicationRowActions
              application={application}
              canManage={canManage}
              showNavigation={false}
              shortcuts={headerShortcuts}
              redirectTo="/applications"
            />
          </div>
        </div>
      </div>

      {/* Padding, not margin: a bottom margin would collapse with `space-y-4`. */}
      {settled ? (
        <div className="pb-2">
          <AttentionStrip items={attentionItems} />
        </div>
      ) : null}

      {/* Until it is serving, the provisioning card is the whole page. */}
      {!settled ? (
        <ProvisioningCard application={application} canManage={canManage} />
      ) : (
        /*
         * Cards are direct grid children so they share a height per row; DOM
         * order is the reading order in one column on a phone.
         */
        <div className="grid items-stretch gap-6 lg:grid-cols-2 xl:grid-cols-3">
          <SiteFactsCard
            application={application}
            canManage={canManage}
            // Type titles arrive translated on the catalog, not from the message files.
            siteTypes={siteTypes.siteTypes}
            className="lg:col-span-2 xl:col-span-3"
          />
          <ProtectionCard application={application} items={protectionItems} />

          {/* Domains stays above Source: certificates are what people come to check. */}
          {canSeeDomains ? (
            <DomainsCard
              application={application}
              domains={domainList.domains}
              certificate={certificate.certificate}
              failed={domainList.failed || certificate.failed}
              href={`/applications/${id}/domains`}
            />
          ) : null}
          {canSeeBackups ? (
            <BackupCard
              applicationId={id}
              target={backup.target}
              backups={backupRuns.backups}
              // The target keeps its last run time after every backup is
              // deleted, so the list decides whether one is actually kept.
              noneKept={!backupRuns.failed && backupRuns.meta?.total === backupRuns.backups.filter((b) => BACKUP_IN_FLIGHT.includes(b.status)).length}
              failed={backup.failed}
              canManage={canRunBackup}
              href={`/applications/${id}/backups`}
            />
          ) : null}

          {/* Shown for every site type; the card itself distinguishes types
              that need a database from those that do not declare it. */}
          {canSeeDatabases ? (
            <DatabaseCard
              application={application}
              unattached={spareDatabases.databases}
              engines={engineList.engines}
              databases={siteDatabases.databases}
              failed={siteDatabases.failed}
              needsDatabase={needsDatabase}
              canSeeDatabases={canManageDatabases}
            />
          ) : null}

          {/* Full width when alone on its line; shares it with a Process card. */}
          {isGit ? (
            <SourceCard
              application={application}
              gitAccounts={gitAccounts}
              canDeploy={canDeploy}
              canSeeDeployment={canSeeDeployment}
              deployInFlight={Boolean(latestDeploy.latest?.in_flight)}
              className={
                application.has_process
                  ? "xl:col-span-2"
                  : "lg:col-span-2 xl:col-span-3"
              }
            />
          ) : null}
          {/* Same rule as Source: a lone card on the last line spans it. */}
          {application.has_process ? (
            <ProcessCard
              application={application}
              canManage={canManage}
              className={isGit ? undefined : "lg:col-span-2 xl:col-span-3"}
            />
          ) : null}
        </div>
      )}
    </div>
  );
}
