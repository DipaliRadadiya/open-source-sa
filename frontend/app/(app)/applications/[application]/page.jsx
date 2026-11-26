import { Suspense } from "react";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { CircleAlert, ExternalLink } from "lucide-react";
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
import { ContainerFailurePanel } from "@/components/applications/container-failure-panel";
import { containerState, hasContainerFailure, healthWarnings } from "@/lib/applications/container-state";
import { FirstRunCredentials } from "@/components/applications/first-run-credentials";
import { ContainerAccessCard } from "@/components/applications/container-access-card";
import { showsContainerAccess } from "@/lib/applications/container-access";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { SiteFactsCard } from "@/components/applications/site-facts-card";
import { SourceCard } from "@/components/applications/source-card";
import { ProcessCard } from "@/components/applications/process-card";
import { DomainsCard } from "@/components/applications/domains-card";
import { ProtectionCard } from "@/components/applications/protection-card";
import { AttentionList } from "@/components/applications/app-status-tiles";
import { RootLockButton } from "@/components/applications/root-lock-button";
import { getRootLock } from "@/lib/applications/get-root-lock";
import { issueItems, localKeysSupersededBy } from "@/lib/applications/issue-items";
import { BackupCard } from "@/components/applications/backup-card";
import { DatabaseCard } from "@/components/applications/database-card";
import { UpdateCardLoader, UpdateCardSkeleton } from "@/components/applications/update-card-loader";
import { UPDATABLE_SITE_TYPES } from "@/lib/applications/app-update";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { MagicLoginLauncher } from "@/components/applications/magic-login-launcher";
import { CopyButton } from "@/components/ui/copy-button";
import { ApplicationOriginBadge } from "@/components/applications/application-origin-badge";
import { ApplicationStatusBadge } from "@/components/applications/application-status-badge";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { firstAllowedScreen } from "@/lib/permissions/app-screens";
import { firstDeployPending, isSettled } from "@/lib/applications/settled";
import { applicationIconDataUri } from "@/lib/applications/application-icon";
import { BreakableDomain } from "@/components/ui/breakable-domain";

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
    // null on a failed read, never []: an empty catalog would mean no access at all.
    getPermissions("application", id).catch(() => null),
    getTranslations("applications"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("title")} />;
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (appPermissions && !can(appPermissions, "app_dashboard", "view", "application")) {
    const first = firstAllowedScreen(appPermissions);
    if (first !== null) redirect(`/applications/${id}${first}`);
    return <PermissionDenied title={application.name} description={t("noScreens")} />;
  }
  const canManage = can(permissions, "application", "manage");
  const canDeploy = can(appPermissions, "app_deployment", "manage", "application");
  const canSeeDeployment = can(appPermissions, "app_deployment", "view", "application");
  const canSeeDomains = can(appPermissions, "app_domain", "view", "application");
  const canSeeChecks = can(appPermissions, "app_dashboard", "view", "application");
  const canMagicLogin = can(appPermissions, "app_magic_login", "manage", "application");
  const canSeeBackups = can(appPermissions, "app_backup", "view", "application");
  const canRunBackup = can(appPermissions, "app_backup", "manage", "application");
  const isGit = Boolean(application.repository || application.repository_url);
  const containerFailed = hasContainerFailure(application);
  const containerFailure = containerFailed ? (
    <ContainerFailurePanel
      application={application}
      canManageContainer={can(appPermissions, "app_container", "manage", "application")}
      canRetry={canManage}
      canSeeLogs={can(appPermissions, "app_log", "view", "application")}
      canEditEnv={can(appPermissions, "app_environment", "view", "application")}
    />
  ) : null;
  const settled = isSettled(application);

  const showFirstRunCredentials =
    !application.credentials_acknowledged &&
    (application.container_secret_keys ?? []).length > 0 &&
    can(appPermissions, "app_container", "manage", "application");

  const showContainerAccess =
    showsContainerAccess(application) &&
    can(appPermissions, "app_compose", "view", "application");

  const gitAccounts =
    application.git_account_missing || application.git_account_id
      ? await getGitAccounts().then((r) => r.accounts ?? []).catch(() => [])
      : [];

  const canSeeDatabases = can(permissions, "database", "view");
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
    settled && canSeeBackups
      ? getApplicationBackups(id, { per_page: 1 })
      : Promise.resolve({ backups: [] }),
    settled && canSeeDatabases
      ? getApplicationDatabases(id)
      : Promise.resolve({ databases: [], failed: false }),
    settled && (canSeeDatabases || canManage)
      ? getSiteTypes().catch(() => ({ siteTypes: [] }))
      : Promise.resolve({ siteTypes: [] }),
    settled && canManageDatabases
      ? getUnattachedDatabases()
      : Promise.resolve({ databases: [] }),
    settled && canManageDatabases ? getEngines() : Promise.resolve({ engines: [] }),
    settled ? getRootLock(id) : Promise.resolve({ rootLock: null, failed: false }),
    settled && isGit && canSeeDeployment
      ? getLatestDeployment(id)
      : Promise.resolve({ latest: null, failed: false }),
    settled && canManage && application.node_version
      ? getNode().catch(() => ({ data: null, failed: true }))
      : Promise.resolve({ data: null, failed: false }),
  ]);
  const folderStatus = rootLock.rootLock?.status ?? null;

  const needsDatabase = siteNeedsDatabase(siteTypes.siteTypes, application.site_type);
  const missingDatabase =
    canSeeDatabases && !siteDatabases.failed && needsDatabase && siteDatabases.databases.length === 0;

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
      on: Boolean(application.ai_bot_policy) && application.ai_bot_policy !== "allow_all",
      state: application.ai_bot_policy_title ?? t("protection.off"),
      href: `/applications/${id}/bot-blocker`,
    },
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
  const allProtections = [
    application.basic_auth_enabled,
    application.waf_enabled,
    application.fail2ban_enabled,
    Boolean(application.ai_bot_policy) && application.ai_bot_policy !== "allow_all",
    folderStatus === "locked" || folderStatus === "unlocked" ? folderStatus === "locked" : undefined,
  ].filter((value) => value !== undefined);
  const protectionTotals = { on: allProtections.filter(Boolean).length, total: allProtections.length };

  const secured = certificate.certificate?.status === "active";
  const certificateIssuing = ["pending", "issuing"].includes(certificate.certificate?.status);
  const siteUrl =
    application.url ?? `${secured ? "https" : "http"}://${application.domain}`;

  const issues = settled && canSeeChecks
    ? await getApplicationIssues(id).catch(() => ({ issues: [], healthy: true, failed: true }))
    : { issues: [], healthy: true, failed: false };
  const deployInFlight = Boolean(latestDeploy.latest?.in_flight);
  const issueList = firstDeployPending(application) || deployInFlight
    ? issues.issues.filter((issue) => issue.type !== "worker")
    : issues.issues;
  const superseded = localKeysSupersededBy(issueList);

  const attentionItems = [
    ...issueItems(issueList, id, (type) => t(`attention.issueAction.${type}`)),
    issues.failed && { key: "checks", label: t("attention.checksFailed") },
    canSeeDomains && !domainList.failed && !certificate.failed && !secured && !certificateIssuing && {
      key: "ssl",
      label: t("attention.noCertificate"),
      action: t("attention.issueCertificate"),
      href: `/applications/${id}/domains?tab=ssl`,
    },
    missingDatabase && {
      key: "database",
      label: t("attention.noDatabase"),
      action: t("attention.attachDatabase"),
      href: "/databases",
    },
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
    ...(["degraded", "failed", "restarting"].includes(containerState(application)) ? healthWarnings(application) : []).map((warning) => ({
      key: `container-${warning.service}`,
      label: t("healthWarnings.item", {
        service: warning.service,
        state: t.has(`helperState.${warning.state}`) ? t(`helperState.${warning.state}`) : warning.state,
      }),
      ...(can(appPermissions, "app_container", "view", "application")
        ? { action: t("healthWarnings.action"), href: `/applications/${id}/container` }
        : null),
    })),
  ]
    .filter(Boolean)
    .filter((item) => !superseded.has(item.key));

  const alerts = attentionItems.filter(
    (item) => item.key !== "backups" && !/^issue-deploy_failed-/.test(item.key),
  );
  const backupState =
    canSeeBackups && !backup.failed
      ? {
          target: backup.target,
          noneKept: nothingKept(backup.target),
        }
      : null;
  const deployTile = isGit && canSeeDeployment ? { inFlight: deployInFlight } : null;

  const domainsCard = canSeeDomains ? (
    <DomainsCard
      application={application}
      domains={domainList.domains}
      certificate={certificate.certificate}
      failed={domainList.failed || certificate.failed}
      href={`/applications/${id}/domains`}
    />
  ) : null;
  const databaseCard = canSeeDatabases ? (
    <DatabaseCard
      application={application}
      unattached={spareDatabases.databases}
      engines={engineList.engines}
      databases={siteDatabases.databases}
      failed={siteDatabases.failed}
      needsDatabase={needsDatabase}
      canSeeDatabases={canManageDatabases}
    />
  ) : null;
  const backupCard = canSeeBackups ? (
    <BackupCard
      applicationId={id}
      target={backup.target}
      backups={backupRuns.backups}
      noneKept={backupState?.noneKept ?? false}
      failed={backup.failed}
      canManage={canRunBackup}
      href={`/applications/${id}/backups`}
      application={application}
      databases={canSeeDatabases && !siteDatabases.failed ? siteDatabases.databases : null}
    />
  ) : null;
  const protectionCard = <ProtectionCard application={application} items={protectionItems} totals={protectionTotals} />;

  return (
    <div className="space-y-6">
      <div className="@container/masthead relative overflow-hidden rounded-2xl border bg-card shadow-e1">
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 bg-[radial-gradient(70%_140%_at_100%_0%,color-mix(in_oklch,var(--primary)_8%,transparent),transparent_60%)]"
        />
        <div className="relative grid grid-cols-[minmax(0,1fr)_auto] gap-3 px-5 py-4 @3xl/masthead:flex @3xl/masthead:items-center @3xl/masthead:justify-between">
          <div className="flex min-w-0 flex-1 items-center gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-card">
              <SiteTypeLogo
                name={application.site_type}
                provider={gitProviderFor(application, providersByAccountId(gitAccounts))}
                iconDataUri={applicationIconDataUri(application, siteTypes.siteTypes)}
                size="h-6 w-6"
              />
            </span>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="min-w-0 text-lg leading-tight font-semibold tracking-tight break-words">{application.name}</h1>
                <ApplicationStatusBadge application={application} deploying={Boolean(deployTile?.inFlight)} />
                <ApplicationOriginBadge origin={application.origin} />
                {application.is_staging ? (
                  <Badge variant="warning" className="font-normal">
                    {t("stagingBadge")}
                  </Badge>
                ) : null}
              </div>
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
                {/* U+2060 keeps the button from wrapping onto its own line. */}
                {"\u2060"}
                <CopyButton value={application.domain} className="-my-1 ml-0.5 inline-flex align-middle" />
              </p>
              {containerFailed ? (
                <p className="mt-1.5 flex w-fit max-w-full items-start gap-1.5 rounded-md bg-destructive-soft px-2 py-1 text-xs leading-4 [overflow-wrap:anywhere] text-destructive">
                  <CircleAlert className="mt-px size-3 shrink-0" aria-hidden />
                  {application.last_failure.message || t("containerFailure.noReason")}
                </p>
              ) : null}
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
                {canMagicLogin ? <MagicLoginLauncher appId={id} variant="default" size="default" /> : null}
              </div>
            ) : null}
            <div className="col-start-2 row-start-1 self-start @3xl/masthead:self-auto">
              <ApplicationRowActions
                application={application}
                canManage={canManage}
                canRemoveSystemUser={can(permissions, "system_user", "manage")}
                canManageDocker={can(permissions, "docker", "manage")}
                showNavigation={false}
                redirectTo="/applications"
                triggerVariant="outline"
                triggerClassName="size-9"
              />
            </div>
          </div>
        </div>
        {settled ? (
          <div className="relative border-t">
            <SiteFactsCard
              application={application}
              canManage={canManage}
              canManageSystemUsers={can(permissions, "system_user", "manage")}
              siteTypes={siteTypes.siteTypes}
              nodeVersions={installedNodeVersions(node.data)}
              nodeVersionsFailed={node.failed}
              strip
            />
          </div>
        ) : null}
      </div>

      {!settled ? (
        <div className="space-y-4">
          {containerFailure}
          <ProvisioningCard
            application={application}
            canManage={canManage && !containerFailed}
          />
        </div>
      ) : (
        <>
          {containerFailure}
          {showFirstRunCredentials ? <FirstRunCredentials application={application} /> : null}

          <AttentionList alerts={alerts} />

          {settled && UPDATABLE_SITE_TYPES.includes(application.site_type) ? (
            <Suspense fallback={<UpdateCardSkeleton />}>
              <UpdateCardLoader
                appId={id}
                appTitle={application.site_type_title ?? application.site_type}
                canManage={canManage}
                backupsHref={canSeeBackups ? `/applications/${id}/backups` : null}
              />
            </Suspense>
          ) : null}

          {isGit ? (
            <SourceCard
              application={application}
              gitAccounts={gitAccounts}
              canDeploy={canDeploy}
              canSeeDeployment={canSeeDeployment}
              deployInFlight={Boolean(latestDeploy.latest?.in_flight)}
            />
          ) : null}
          {showContainerAccess ? (
            <>
              {databaseCard ? (
                <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
                  {domainsCard}
                  {databaseCard}
                </div>
              ) : (
                domainsCard
              )}
              <div className="grid gap-6 lg:grid-cols-2">
                {protectionCard}
                {backupCard}
              </div>
              <ContainerAccessCard
                application={application}
                canSeeSystemUsers={can(permissions, "system_user", "view")}
                canManageSystemUsers={can(permissions, "system_user", "manage")}
                canOpenSecurity={can(permissions, "setting", "manage")}
              />
            </>
          ) : (
            <div className="grid gap-6 lg:grid-cols-2">
              {domainsCard}
              {databaseCard}
              {backupCard}
              {protectionCard}
            </div>
          )}
          {application.has_process ? <ProcessCard application={application} canManage={canManage} deploying={deployInFlight} /> : null}
        </>
      )}
    </div>
  );
}

