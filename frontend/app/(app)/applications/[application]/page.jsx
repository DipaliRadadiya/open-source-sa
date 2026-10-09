import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { ExternalLink } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { getApplicationDatabases, getEngines, getUnattachedDatabases } from "@/lib/databases/get-databases";
import { getSiteTypes } from "@/lib/applications/get-applications";
import { getNode } from "@/lib/node/get-node";
import { installedNodeVersions } from "@/lib/node/installed-node-versions";
import { siteNeedsDatabase } from "@/lib/backups/database-availability";
import { can } from "@/lib/permissions/can";
import { getApplication, getApplicationIssues } from "@/lib/applications/get-applications";
import { getApplicationBackups, getBackupTarget } from "@/lib/backups/get-backups";
import { nothingKept } from "@/lib/backups/reason";
import { getGitAccounts } from "@/lib/git/get-git";
import { gitProviderFor, providersByAccountId } from "@/lib/applications/git-provider";
import { getLatestDeployment } from "@/lib/applications/get-deployments";
import {
  getApplicationDomains,
  getApplicationCertificate,
} from "@/lib/applications/get-application-domains";
import { ProvisioningCard } from "@/components/applications/provisioning-card";
import { FirstRunCredentials } from "@/components/applications/first-run-credentials";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { SiteFactsCard } from "@/components/applications/site-facts-card";
import { SourceCard } from "@/components/applications/source-card";
import { ProcessCard } from "@/components/applications/process-card";
import { DomainsCard } from "@/components/applications/domains-card";
import { ProtectionCard } from "@/components/applications/protection-card";
import { AppStatusTiles } from "@/components/applications/app-status-tiles";
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
  // The API already gates this by site type; a new type must not need a frontend change.
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

  // The generated credentials, until somebody says they have saved them. Gated on
  // `app_container` manage -- the same permission the values are behind.
  const showFirstRunCredentials =
    !application.credentials_acknowledged &&
    (application.container_secret_keys ?? []).length > 0 &&
    can(appPermissions, "app_container", "manage", "application");

  // The payload has `git_account_id` but no provider. `cache()`d, no provider API call.
  const gitAccounts =
    application.git_account_missing || application.git_account_id
      // The fetcher already unwraps the envelope: `.accounts`, not `.data.git_accounts`.
      ? await getGitAccounts().then((r) => r.accounts ?? []).catch(() => [])
      : [];

  // Databases are a SERVER-level permission; a site-level reader may hold none.
  const canSeeDatabases = can(permissions, "database", "view");
  // Attaching and creating need manage; viewers get the card without buttons.
  const canManageDatabases = can(permissions, "database", "manage");

  const [domainList, certificate, backup, backupRuns, siteDatabases, siteTypes, spareDatabases, engineList, rootLock, latestDeploy, node] = await Promise.all([
    settled && canSeeDomains
      ? getApplicationDomains(id)
      : Promise.resolve({ domains: [], failed: false }),
    settled && canSeeDomains
      ? getApplicationCertificate(id)
      : Promise.resolve({ certificate: null, failed: false }),
    settled && canSeeBackups
      ? getBackupTarget(id)
      : Promise.resolve({ target: null, failed: false }),
    // One row is enough: `GET /backups` orders by newest id, so a run in flight is first.
    settled && canSeeBackups
      ? getApplicationBackups(id, { per_page: 1 })
      : Promise.resolve({ backups: [] }),
    settled && canSeeDatabases
      ? getApplicationDatabases(id)
      : Promise.resolve({ databases: [], failed: false }),
    // `needs_database` is on the site type; the facts card also needs the list to relabel.
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
    // For the Node.js fact's version picker; only someone who can change it needs the list.
    settled && canManage && application.node_version
      ? getNode().catch(() => ({ data: null, failed: true }))
      : Promise.resolve({ data: null, failed: false }),
  ]);
  const folderStatus = rootLock.rootLock?.status ?? null;

  const needsDatabase = siteNeedsDatabase(siteTypes.siteTypes, application.site_type);
  // Only when the list was read and empty, for a type that wants a database.
  const missingDatabase =
    canSeeDatabases && !siteDatabases.failed && needsDatabase && siteDatabases.databases.length === 0;

  // Each row is gated on the grant that guards its screen, so no row links to a dead end.
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
    // `unknown` or a failed read must not render as "Not locked".
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


  // Use the server's `application.url`; deriving the scheme from a failed certificate
  // read would downgrade an https-only site. The fallback is for older APIs.
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
    // Switched-off protections are deliberately not findings: each is a per-site choice.
  ]
    .filter(Boolean)
    // Drop local inferences the server's own findings already cover.
    .filter((item) => !superseded.has(item.key));

  // A paused site serves a holding page: worth saying first, but not a fault.
  // Covered by a status tile, so not repeated as a line under them.
  const tileKeys = new Set(["ssl", "backups"]);
  const alerts = attentionItems.filter(
    (item) => !tileKeys.has(item.key) && !/^issue-(certificate|deploy_failed)-/.test(item.key),
  );
  // Each tile only when its read succeeded: a failed read is not a fact.
  const httpsTile =
    canSeeDomains && !certificate.failed
      ? {
          secured,
          issuing: certificateIssuing,
          expiringSoon: Boolean(certificate.certificate?.expiring_soon),
          expiresHuman: certificate.certificate?.expires_at_human ?? null,
        }
      : null;
  const backupTile =
    canSeeBackups && !backup.failed
      ? {
          target: backup.target,
          noneKept: nothingKept(backup.target),
        }
      : null;
  const deployTile = isGit && canSeeDeployment ? { inFlight: Boolean(latestDeploy.latest?.in_flight) } : null;

  return (
    <div className="space-y-6">
      {/* The header card IS the application's details (Krishna, 7 Oct: they are what this
          page is for and were far down it). Identity and actions on top, the facts below;
          an 18px name, since the 20px one still read as a banner. */}
      <div className="@container/masthead relative overflow-hidden rounded-2xl border bg-card shadow-e1">
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 bg-[radial-gradient(70%_140%_at_100%_0%,color-mix(in_oklch,var(--primary)_8%,transparent),transparent_60%)]"
        />
        {/* Narrow: a grid, so ⋯ sits beside the name and the buttons fill the row below
            (on its own line it looked forgotten). Wide: one flex row. */}
        <div className="relative grid grid-cols-[minmax(0,1fr)_auto] gap-3 px-5 py-4 @3xl/masthead:flex @3xl/masthead:items-center @3xl/masthead:justify-between">
          <div className="flex min-w-0 flex-1 items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-card">
              <SiteTypeLogo
                name={application.site_type}
                provider={gitProviderFor(application, providersByAccountId(gitAccounts))}
                size="h-6 w-6"
              />
            </span>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="min-w-0 text-lg leading-tight font-semibold tracking-tight break-words">{application.name}</h1>
                <ApplicationStatusBadge application={application} />
                {/* Staging copies look like the site they copy; mark them where the name is. */}
                {application.is_staging ? (
                  <Badge variant="warning" className="font-normal">
                    {t("stagingBadge")}
                  </Badge>
                ) : null}
              </div>
              {/* Inline text, not flex: on a phone the domain wraps at its dots and both
                  icons follow its last letter (break-all split "116" and left the copy
                  button floating beside the first line). */}
              <p className="mt-0.5 font-mono text-[13px] leading-5 [overflow-wrap:anywhere]">
                {application.status === "active" ? (
                  <a href={siteUrl} target="_blank" rel="noreferrer" className="text-primary underline-offset-4 hover:underline">
                    <BreakableDomain domain={application.domain}>
                      <ExternalLink className="ml-1 inline size-3.5 align-[-2px]" aria-hidden />
                    </BreakableDomain>
                  </a>
                ) : (
                  <BreakableDomain domain={application.domain} />
                )}
                {/* U+2060 joins the button to the text so it never wraps alone. */}
                {"\u2060"}
                <CopyButton value={application.domain} className="-my-1 ml-0.5 inline-flex align-middle" />
              </p>
            </div>
          </div>

          <div className="contents @3xl/masthead:flex @3xl/masthead:shrink-0 @3xl/masthead:items-center @3xl/masthead:gap-2">
            {application.status === "active" ? (
              <div className="col-span-2 flex flex-wrap gap-2 *:grow @3xl/masthead:*:grow-0">
                <Button asChild variant="outline">
                  <a href={siteUrl} target="_blank" rel="noreferrer">
                    <ExternalLink className="size-4" />
                    {t("actions.visit")}
                  </a>
                </Button>
                {/* Beside Visit and filled: signing in as the site's administrator is what
                    people open this page for most. */}
                {canMagicLogin ? <MagicLoginLauncher appId={id} variant="default" size="default" /> : null}
              </div>
            ) : null}
            <div className="col-start-2 row-start-1 self-start @3xl/masthead:self-auto">
              <ApplicationRowActions
                application={application}
                canManage={canManage}
                canRemoveSystemUser={can(permissions, "system_user", "manage")}
                showNavigation={false}
                shortcuts={headerShortcuts}
                redirectTo="/applications"
                triggerVariant="outline"
                triggerClassName="size-9"
              />
            </div>
          </div>
        </div>
        {/* Only once it is serving: before that there is no web root or size to show. */}
        {settled ? (
          <div className="relative border-t bg-muted/20 px-5 py-3.5">
            <SiteFactsCard
              application={application}
              canManage={canManage}
              // Type titles arrive translated on the catalog, not from the message files.
              siteTypes={siteTypes.siteTypes}
              nodeVersions={installedNodeVersions(node.data)}
              nodeVersionsFailed={node.failed}
              strip
            />
          </div>
        ) : null}
      </div>

      {/* Until it is serving, the provisioning card is the whole page. */}
      {!settled ? (
        <ProvisioningCard application={application} canManage={canManage} />
      ) : (
        <>
          {/* It disappears for good once somebody says they have saved them, and
              these cannot be rotated from the panel. */}
          {showFirstRunCredentials ? <FirstRunCredentials application={application} /> : null}

          {/* The four answers people come for (is it up, secure, restorable, deployed),
              each said once; then two columns: what you work on, and the reference.
              Krishna, 6 Oct: the old rows repeated each fact in two cards. */}
          <AppStatusTiles
            application={application}
            appId={id}
            https={httpsTile}
            backup={backupTile}
            deploy={deployTile}
            alerts={alerts}
          />

          {/* Deploy and Process are wide; the rest is an aligned two-by-two grid, every
              card the same shape: title with its state, one button, rows (Krishna, 7 Oct). */}
          {isGit ? (
            <SourceCard
              application={application}
              gitAccounts={gitAccounts}
              canDeploy={canDeploy}
              canSeeDeployment={canSeeDeployment}
              deployInFlight={Boolean(latestDeploy.latest?.in_flight)}
            />
          ) : null}
          <div className="grid gap-6 lg:grid-cols-2">
            {canSeeDomains ? (
              <DomainsCard
                application={application}
                domains={domainList.domains}
                certificate={certificate.certificate}
                failed={domainList.failed || certificate.failed}
                href={`/applications/${id}/domains`}
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
            {canSeeBackups ? (
              <BackupCard
                applicationId={id}
                target={backup.target}
                backups={backupRuns.backups}
                // The target keeps its last run time after every backup is
                // deleted, so the list decides whether one is actually kept.
                noneKept={backupTile?.noneKept ?? false}
                failed={backup.failed}
                canManage={canRunBackup}
                href={`/applications/${id}/backups`}
              />
            ) : null}
            <ProtectionCard application={application} items={protectionItems} />
            {application.has_process ? (
              <ProcessCard application={application} canManage={canManage} className="lg:col-span-2" />
            ) : null}
          </div>
        </>
      )}
    </div>
  );
}

// A break opportunity after each dot; the last label stays joined to whatever
// follows it (the external-link icon), so an icon never starts a line.
function BreakableDomain({ domain, children }) {
  const labels = String(domain ?? "").split(".");
  const last = labels.pop();
  return (
    <>
      {labels.map((label, i) => (
        <span key={i}>
          {label}.<wbr />
        </span>
      ))}
      <span className="whitespace-nowrap">
        {last}
        {children}
      </span>
    </>
  );
}
