import Link from "next/link";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { ExternalLink } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getBackupTarget, getBackups } from "@/lib/backups/get-backups";
import { getGitAccounts } from "@/lib/git/get-git";
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
import { BackupCard } from "@/components/applications/backup-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { ApplicationStatusBadge } from "@/components/applications/application-status-badge";

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

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  // The site is gone. Land on the list — the only place left to go — and say
  // why on arrival, rather than parking on a dead end that offers one link.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} />;

  const application = result.application;
  const canManage = can(permissions, "application", "manage");
  const canDeploy = can(appPermissions, "app_deployment", "manage", "application");
  const canSeeDomains = can(appPermissions, "app_domain", "view", "application");
  // The screens people come back to; the sidebar carries the rest. Filtered
  // here rather than in the menu so permission checks stay on the server.
  const headerShortcuts = [
    can(appPermissions, "app_file", "view", "application") && "files",
    canSeeDomains && "domains",
    can(appPermissions, "app_backup", "view", "application") && "backups",
  ].filter(Boolean);
  const canSeeBackups = can(appPermissions, "app_backup", "view", "application");
  const canRunBackup = can(appPermissions, "app_backup", "manage", "application");
  const isGit = Boolean(application.repository || application.repository_url);
  // Only a serving site has domains, a certificate or a running process. While
  // it is still being built, saying anything about them would be invention.
  const settled = application.status === "active";

  // Only when the site has lost its account: this is the list the repair
  // dialog picks from, and fetching it for every healthy site would be a
  // request per page view for a dialog nobody opens.
  const gitAccounts = application.git_account_missing
    ? await getGitAccounts().then((r) => r.data?.git_accounts ?? []).catch(() => [])
    : [];

  const [domainList, certificate, backup, backupRuns] = await Promise.all([
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
     * The target says what is scheduled; it says nothing about what is running
     * right now. Without this the card goes on claiming "Protected · last
     * backup 18 hours ago" throughout a run — including a scheduled one, or one
     * a colleague started, which no amount of local click-state would catch.
     *
     * One row is enough: `GET /backups` orders by newest id, so a run in flight
     * is always the first row back.
     */
    settled && canSeeBackups
      ? getBackups({ application: id, per_page: 1 })
      : Promise.resolve({ backups: [] }),
  ]);

  /*
   * Every value here is already on the application payload, so the card costs
   * no request. Each row is gated on the same application-level grant that
   * guards its screen — a row linking somewhere the reader cannot open is a
   * dead end, and the sidebar has already filtered those out for this site
   * type (a static site has no PHP screen to protect).
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
      // The mode matters: "watch, don't block" is on but not blocking, and
      // calling that simply "On" would overstate what it does.
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
      // A policy, not a switch. "on" here means it blocks something at all,
      // which is what the icon is claiming.
      on: Boolean(application.ai_bot_policy) && application.ai_bot_policy !== "allow_all",
      state: application.ai_bot_policy_title ?? t("protection.off"),
      href: `/applications/${id}/bot-blocker`,
    },
  ].filter(Boolean);


  // Link over https only when a certificate is actually serving — otherwise
  // https lands on a browser security warning; the site is still on http.
  const secured = certificate.certificate?.status === "active";
  const siteUrl = `${secured ? "https" : "http"}://${application.domain}`;

  /*
   * The three risks worth interrupting for, in the order a site is usually
   * lost: no way back (no backup), traffic in the clear (no certificate), then
   * nothing turned on to guard it.
   *
   * Each is only claimed when we actually know it. A failed backup read or a
   * missing domain permission says nothing here rather than accusing a site of
   * being unprotected on the strength of a request that did not come back.
   */
  const protectionsOff = protectionItems.filter((item) => !item.on);

  const attentionItems = [
    canSeeDomains && !domainList.failed && !certificate.failed && !secured && {
      key: "ssl",
      label: t("attention.noCertificate"),
      action: t("attention.issueCertificate"),
      // ?tab=ssl, not the bare screen. The Domains page opens on its Domains
      // tab, so a button saying "Issue SSL" was landing people on a domain list
      // and asking them to find the second tab themselves.
      href: `/applications/${id}/domains?tab=ssl`,
    },
    canSeeBackups && !backup.failed && !backup.target && {
      key: "backups",
      label: t("attention.noBackups"),
      action: t("attention.setUpBackups"),
      href: `/applications/${id}/backups`,
    },
    // Counted, not all-or-nothing. Firing only when every protection is off
    // meant a site with three of four switched off said nothing at all — the
    // exact case somebody needs telling about.
    protectionsOff.length > 0 && {
      key: "protection",
      label: t("attention.protectionsOff", { count: protectionsOff.length }),
      action: t("attention.reviewSecurity"),
      /*
       * The Security card below, not the first screen that happens to be off.
       * "3 protections off" followed by a jump into Password Protection told
       * the reader they were reviewing security and then showed them one
       * setting — the other two were never mentioned again. There is no
       * security overview screen to link to because that card IS the overview,
       * and each of its rows already routes to its own screen.
       */
      href: "#security",
    },
  ].filter(Boolean);

  return (
    // 4, not 6, between the header, the strip and the grid. Those three are one
    // masthead — name, then what is wrong, then the detail — and 24px gaps read
    // as three unrelated blocks with the page's first card pushed 178px down.
    // The grid keeps gap-6, so cards still breathe; only the run-in tightens.
    <div className="space-y-4">
      <div className="space-y-2">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="min-w-0 text-2xl font-semibold tracking-tight break-words">{application.name}</h1>
              <ApplicationStatusBadge application={application} />
              <Badge variant="secondary" className="font-normal">
                {application.site_type_title ?? application.site_type}
              </Badge>
              {/* A staging copy is otherwise indistinguishable from the site
                  it copies, and the two are usually one letter apart in the
                  switcher. Editing the wrong one is the exact mistake staging
                  exists to prevent, so the copy says so where the name is. */}
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

          <div className="flex items-center gap-2">
            {/* Outline, not filled. Four solid buttons on one page means none of
                them leads — and this was the least consequential of the four,
                sitting at the top in the same weight as "this site has no
                backups". The domain directly beneath it is already a link to
                the same place, so nothing is lost. Filled blue is reserved for
                what a card is asking you to DO. */}
            {application.status === "active" ? (
              <Button asChild variant="outline" size="sm">
                <a href={siteUrl} target="_blank" rel="noreferrer">
                  <ExternalLink className="size-4" />
                  {t("actions.visit")}
                </a>
              </Button>
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

      {/* Padding, not margin: a bottom margin here collapses against the grid's
          own top margin from `space-y-4` and the strip ends up CLOSER to the
          cards, not further. Padding never collapses, so this reliably adds to
          the 16px — the strip belongs to the header above it, and the cards
          below are a separate block. */}
      {settled ? (
        <div className="pb-2">
          <AttentionStrip items={attentionItems} />
        </div>
      ) : null}

      {/* Until it is serving, the provisioning card IS the page: cards about a
          web root, a certificate and a process that do not exist yet are worse
          than nothing. */}
      {!settled ? (
        <ProvisioningCard application={application} canManage={canManage} />
      ) : (
        /*
         * Three across on a wide screen, not a 2x2 of equal cards.
         *
         * Four identical cards in a two-by-two IS the "identical feature card
         * grid" anti-pattern by name — it reads as boilerplate and it forced a
         * second row that a 1440px screen had to scroll to reach. Security,
         * Domains and Backups are the same KIND of thing (what state is this
         * site in), so they belong on one line; Source and Process are a
         * different kind and get the line below.
         *
         * Cards are direct grid children so they fall into real rows and share
         * a height per row. Reading order is DOM order, so it survives the drop
         * to one column on a phone.
         */
        <div className="grid items-stretch gap-6 lg:grid-cols-2 xl:grid-cols-3">
          {/* Full width, like the server dashboard identity band. Eight short
              facts stacked two-up in a half-width card was a tall narrow column
              of "8.4" and "/" — the same content across the row is one glance
              instead of four. */}
          <SiteFactsCard
            application={application}
            canManage={canManage}
            className="lg:col-span-2 xl:col-span-3"
          />
          <ProtectionCard application={application} items={protectionItems} />

          {/* Row 2. Domains stays above Source deliberately: a certificate is
              the thing people come to a site's page worried about, and the
              deploy log is not. */}
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
              failed={backup.failed}
              canManage={canRunBackup}
              href={`/applications/${id}/backups`}
            />
          ) : null}

          {/* Full width when it is the only card on its line — a card
              spanning two of three columns leaves an empty third that reads as
              a missing card. It gives up one column when a Process card is
              there to fill the gap. */}
          {isGit ? (
            <SourceCard
              application={application}
              gitAccounts={gitAccounts}
              canDeploy={canDeploy}
              className={
                application.has_process
                  ? "xl:col-span-2"
                  : "lg:col-span-2 xl:col-span-3"
              }
            />
          ) : null}
          {/* Same rule as Source: a lone card on the last line spans it. With
              a Git site the two share the line and neither needs to grow. */}
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
