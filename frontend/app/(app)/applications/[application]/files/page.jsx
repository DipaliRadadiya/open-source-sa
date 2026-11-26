import Link from "@/components/ui/app-link";
import { FileCode, FolderSearch, FolderX } from "lucide-react";
import { PageHeader } from "@/components/ui/page-header";
import { redirect } from "next/navigation";
import { cookies } from "next/headers";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getFiles } from "@/lib/applications/get-files";
import { basename, dirname } from "@/lib/files/path-helpers";
import { HIDDEN_COOKIE, SORT_COOKIE, parseSort, resolveShowHidden } from "@/lib/files/view-prefs";
import { getTrash } from "@/lib/applications/get-trash";
import { getBreakdown } from "@/lib/applications/get-breakdown";
import { FilesPanel } from "@/components/applications/files/files-panel";
import { FilesRootProvider } from "@/components/applications/files/files-root-context";
import { filesRoot } from "@/lib/files/server-path";
import { TrashPanel } from "@/components/applications/files/trash-panel";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Button } from "@/components/ui/button";
import { Note } from "@/components/ui/note";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

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
  const { path: rawPath, trash: rawTrash, hidden: rawHidden, open: rawOpen } = await searchParams;
  const path = typeof rawPath === "string" ? rawPath : "";
  const openName = typeof rawOpen === "string" ? rawOpen : null;
  const showTrash = rawTrash === "1";
  const cookieStore = await cookies();
  const showHidden = resolveShowHidden(rawHidden, cookieStore.get(HIDDEN_COOKIE)?.value);
  const initialSort = parseSort(cookieStore.get(SORT_COOKIE)?.value);

  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.files"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (!can(appPermissions, "app_file", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }
  const canManage = can(appPermissions, "app_file", "manage", "application");
  const settled = isSettled(application);

  if (!isSafePath(path)) redirect(`/applications/${id}/files`);

  const [filesResult, breakdown] = await Promise.all([
    settled && !showTrash
      ? getFiles(id, path, showHidden)
      : Promise.resolve({ path: "", files: [], failed: false, notFound: false }),
    settled && !showTrash ? getBreakdown(id, path) : Promise.resolve(null),
  ]);
  const trashResult = settled && showTrash ? await getTrash(id) : null;

  if (filesResult.notFound && path) {
    const parent = dirname(path);
    const name = basename(path);
    const parentResult = await getFiles(id, parent, true);
    if (parentResult.files?.some((entry) => entry.name === name && entry.type !== "dir")) {
      const query = new URLSearchParams({ ...(parent ? { path: parent } : {}), open: name });
      redirect(`/applications/${id}/files?${query}`);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t("pageTitle")} />

      {settled && application.serving_profile === "docker" ? (
        <Note icon={FileCode}>
          {t.rich("dockerNote", {
            link: (chunks) =>
              can(appPermissions, "app_compose", "view", "application") ? (
                <Link href={`/applications/${id}/compose`} prefetch={false} className="font-medium text-primary underline-offset-4 hover:underline">
                  {chunks}
                </Link>
              ) : (
                <span className="font-medium text-foreground">{chunks}</span>
              ),
            code: (chunks) => <code className="font-mono text-xs text-foreground">{chunks}</code>,
          })}
        </Note>
      ) : null}

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : showTrash ? (
        <TrashPanel
          appId={id}
          trash={trashResult.trash}
          totalSize={trashResult.totalSize}
          retentionDays={trashResult.retentionDays}
          failed={trashResult.failed}
          status={trashResult.status}
          failure={trashResult.failure} message={trashResult.message} debug={trashResult.debug}
          canManage={canManage}
          backHref={`/applications/${id}/files`}
        />
      ) : filesResult.notFound && !path ? (
        <EmptyState
          icon={FolderSearch}
          title={t("notProvisioned.title")}
          description={t("notProvisioned.description")}
        />
      ) : filesResult.notFound ? (
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
        <LoadFailed description={t("loadFailed")} status={filesResult.status} failure={filesResult.failure} message={filesResult.message} debug={filesResult.debug} />
      ) : (
        // Keyed on path so changing folder resets the selection and dialogs.
        <FilesRootProvider value={filesRoot(application)}>
          <FilesPanel
            key={filesResult.path}
            appId={id}
            initialPath={filesResult.path}
            initialFiles={filesResult.files}
            hiddenCount={filesResult.hiddenCount}
            showHidden={showHidden}
            initialSort={initialSort}
            canManage={canManage}
            breakdown={breakdown}
            siteType={application.site_type}
            servingProfile={application.serving_profile}
            openName={openName}
          />
        </FilesRootProvider>
      )}
    </div>
  );
}
