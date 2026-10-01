import { Globe2 } from "lucide-react";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplications, getSiteTypes } from "@/lib/applications/get-applications";
import { getDatabaseCounts } from "@/lib/databases/get-databases";
import { getGitAccounts } from "@/lib/git/get-git";
import { providersByAccountId } from "@/lib/applications/git-provider";
import { sitesMissingDatabase } from "@/lib/backups/database-availability";
import { ApplicationsTable } from "@/components/applications/applications-table";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { PageHeader } from "@/components/ui/page-header";
import { RefreshOnReturn } from "@/components/ui/refresh-on-return";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("applications");
  return { title: t("title") };
}

export default async function ApplicationsPage({ searchParams }) {
  const sp = await searchParams;
  // Serialised so React's `cache` sees a stable primitive and can dedupe.
  const query = new URLSearchParams(
    Object.entries(sp ?? {}).filter(([, v]) => typeof v === "string"),
  ).toString();

  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    // Application-level catalog, unfiltered: role grants are global, so one
    // call answers Magic Login for every row.
    getPermissions("application").catch(() => []),
    getTranslations("applications"),
    getApplications(query),
  ]);
  // Filter options come from the catalog, not the current page of rows.
  const { siteTypes } = await getSiteTypes();

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("title")} />;
  // Without the server-level database permission, no missing-database marker.
  const dbCounts = can(permissions, "database", "view")
    ? await getDatabaseCounts()
    : { counts: null, known: false };

  /*
   * Git accounts, only to tell each account-linked git site's provider (the
   * payload carries no host). Skipped when no row needs it or without the
   * `git` permission; a failure falls back to the generic git mark.
   */
  const needsGitAccounts =
    can(permissions, "git", "view") &&
    result.applications?.some(
      (application) =>
        application.site_type === "git" &&
        application.git_account_id !== null &&
        application.git_account_id !== undefined,
    );
  const gitProviders = needsGitAccounts
    ? providersByAccountId(await getGitAccounts().then((r) => r.accounts ?? []).catch(() => []))
    : new Map();
  // A 422 means a bad filter/sort in the URL: drop them, keep the search.
  if (result.failed && result.status === 422 && (sp?.status || sp?.site_type || sp?.sort)) {
    const kept = new URLSearchParams();
    if (typeof sp.search === "string" && sp.search) kept.set("search", sp.search);
    redirect(`/applications${kept.size ? `?${kept}` : ""}`);
  }
  if (result.failed) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;


  // A page past the end redirects to the last real page.
  redirectOutOfRange("/applications", sp, result.meta, result.failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />
      <RefreshOnReturn />
      {/* `?gone` is set when a deleted site redirects here; explain the redirect. */}
      {sp?.gone ? (
        <div className="flex items-start gap-2.5 rounded-lg border bg-muted/40 p-3 text-sm">
          <Globe2 className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
          <span>
            <span className="font-medium">{t("missing.title")}</span>{" "}
            <span className="text-muted-foreground">{t("missing.description")}</span>
          </span>
        </div>
      ) : null}
      <ApplicationsTable
        applications={result.applications}
        meta={result.meta}
        siteTypes={siteTypes}
        canManage={can(permissions, "application", "manage")}
        // The API enforces `app_magic_login`, not `application` manage. The
        // catalog is unfiltered by site type, so the row checks `site_type`.
        canMagicLogin={can(appPermissions, "app_magic_login", "manage", "application")}
        gitProviders={gitProviders}
        missingDatabase={sitesMissingDatabase(
          result.applications,
          siteTypes,
          dbCounts.counts,
          dbCounts.known,
        )}
      />
    </div>
  );
}
