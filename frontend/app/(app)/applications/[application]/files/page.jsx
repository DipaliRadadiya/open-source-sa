import Link from "next/link";
import { FolderSearch, FolderX } from "lucide-react";
import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getFiles } from "@/lib/applications/get-files";
import { FilesPanel } from "@/components/applications/files/files-panel";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Button } from "@/components/ui/button";

export const dynamic = "force-dynamic";

// Same rule the backend applies (App\Rules\SafeRelativePath) — catches a
// mangled ?path= before it round-trips, same spirit as the client-side check
// on write operations.
function isSafePath(path) {
  if (!path) return true;
  if (path.startsWith("/")) return false;
  return !path.split("/").some((seg) => seg === "." || seg === "..");
}

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.files"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationFilesPage({ params, searchParams }) {
  const { application: id } = await params;
  const { path: rawPath } = await searchParams;
  const path = typeof rawPath === "string" ? rawPath : "";

  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.files"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} />;

  const application = result.application;
  if (!can(appPermissions, "app_file", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  const canManage = can(appPermissions, "app_file", "manage", "application");
  const settled = application.status === "active";

  if (!isSafePath(path)) redirect(`/applications/${id}/files`);

  const filesResult = settled ? await getFiles(id, path) : { path: "", files: [], failed: false, notFound: false };

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : filesResult.notFound && !path ? (
        // Root 404 means nothing was ever provisioned here — a different
        // situation from a subfolder vanishing, and "deleted since you last
        // viewed it" would be a false claim for a path never seen before.
        <EmptyState
          icon={FolderSearch}
          title={t("notProvisioned.title")}
          description={t("notProvisioned.description")}
        />
      ) : filesResult.notFound ? (
        // The path itself is gone (deleted from under us, or a stale link) —
        // not a load failure, so it gets its own message and a way back to root.
        <EmptyState
          icon={FolderX}
          title={t("pathNotFound.title")}
          description={t("pathNotFound.description")}
          action={
            <Button asChild variant="outline" size="sm">
              <Link href={`/applications/${id}/files`}>{t("pathNotFound.action")}</Link>
            </Button>
          }
        />
      ) : filesResult.failed ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <FilesPanel
          appId={id}
          initialPath={filesResult.path}
          initialFiles={filesResult.files}
          canManage={canManage}
        />
      )}
    </div>
  );
}
