import { redirect } from "next/navigation";
import { Globe2 } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplications, getSiteTypes } from "@/lib/applications/get-applications";
import { getDatabaseCounts } from "@/lib/databases/get-databases";
import { sitesMissingDatabase } from "@/lib/backups/database-availability";
import { ApplicationsTable } from "@/components/applications/applications-table";
import { LoadFailed } from "@/components/data-table/load-failed";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("applications");
  return { title: t("title") };
}

export default async function ApplicationsPage({ searchParams }) {
  const sp = await searchParams;
  // Serialised so React's `cache` sees a stable primitive argument — an object
  // literal is a fresh identity on every call and would defeat the dedupe.
  const query = new URLSearchParams(
    Object.entries(sp ?? {}).filter(([, v]) => typeof v === "string"),
  ).toString();

  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    // The application-level catalog, unfiltered: role grants are global,
    // and `application_id` only narrows the list by that one site's
    // features. So one call answers "does this user hold Magic Login" for
    // every row, instead of one request per application.
    getPermissions("application").catch(() => []),
    getTranslations("applications"),
    getApplications(query),
  ]);
  // The filter's options come from the catalog, not from the ten rows we hold.
  const { siteTypes } = await getSiteTypes();

  if (!can(permissions, "application", "view")) redirect("/dashboard");

  // Databases are a server-level permission: a reader without it gets no
  // marker rather than a marker they could do nothing about.
  const dbCounts = can(permissions, "database", "view")
    ? await getDatabaseCounts()
    : { counts: null, known: false };
  if (result.failed) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} />;


  // Before anything renders: a page past the end sends the reader to the
  // last real page instead of painting an error for it.
  redirectOutOfRange("/applications", sp, result.meta, result.failed);
  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
      {/* Opening a site that no longer exists lands here, because the list is
          the only place left to go. Saying so on arrival is what separates a
          redirect from being silently teleported somewhere you did not ask for. */}
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
        // Gated on the Magic Login permission, not on `application`
        // manage. The API enforces `app_magic_login`, so showing it to
        // an application manager who lacks that grant would render a
        // button whose only outcome is a 403.
        //
        // The catalog here is unfiltered by site type, so unlike the
        // Dashboard the row has to check `site_type` itself.
        canMagicLogin={can(appPermissions, "app_magic_login", "manage", "application")}
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
