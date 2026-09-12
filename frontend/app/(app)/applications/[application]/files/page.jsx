import Link from "next/link";
import { FolderSearch, FolderX } from "lucide-react";
import { PageHeader } from "@/components/ui/page-header";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getFiles } from "@/lib/applications/get-files";
import { getTrash } from "@/lib/applications/get-trash";
import { getBreakdown } from "@/lib/applications/get-breakdown";
import { FilesPanel } from "@/components/applications/files/files-panel";
import { TrashPanel } from "@/components/applications/files/trash-panel";
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
  const { path: rawPath, trash: rawTrash, hidden: rawHidden } = await searchParams;
  const path = typeof rawPath === "string" ? rawPath : "";
  // The trash is a view of this same screen, not a route of its own — see
  // memory/research-file-trash.md. Every panel that has one reaches it from the
  // file manager's toolbar.
  const showTrash = rawTrash === "1";
  // In the URL rather than in the browser's storage: the listing is fetched on
  // the server, so the choice has to reach the server to have any effect. It
  // also makes the state shareable, survives a reload, and needs no effect
  // reading localStorage after mount.
  const showHidden = rawHidden !== "0";

  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.files"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  // The site is gone. Land on the list — the only place left to go — and say
  // why on arrival, rather than parking on a dead end that offers one link.
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} />;

  const application = result.application;
  if (!can(appPermissions, "app_file", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  const canManage = can(appPermissions, "app_file", "manage", "application");
  const settled = application.status === "active";

  if (!isSafePath(path)) redirect(`/applications/${id}/files`);

  // Together, not in sequence: the breakdown walks the directory while the
  // listing reads one level of it, and running them one after the other would
  // add the slower one's time to a page that already waits on a shell-out.
  const [filesResult, breakdown] = await Promise.all([
    settled && !showTrash
      ? getFiles(id, path, showHidden)
      : Promise.resolve({ path: "", files: [], failed: false, notFound: false }),
    // Never blocks: a folder too large to walk returns null and the card says
    // so, rather than the file manager waiting on a chart.
    settled && !showTrash ? getBreakdown(id, path) : Promise.resolve(null),
  ]);
  const trashResult = settled && showTrash ? await getTrash(id) : null;

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
      ) : showTrash ? (
        <TrashPanel
          appId={id}
          trash={trashResult.trash}
          totalSize={trashResult.totalSize}
          retentionDays={trashResult.retentionDays}
          failed={trashResult.failed}
          canManage={canManage}
          backHref={`/applications/${id}/files`}
        />
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
        // No rail. The breakdown was a 340px column beside the listing, held
        // back to 2xl because below that the listing's seven columns were
        // narrower than their own content — and compensating with a width
        // floor put Download and Copy behind a horizontal scroll, the two
        // controls people reach for most.
        //
        // 2xl only moved that cost to the widest screens rather than removing
        // it: a listing is what this page is for, and it was still giving up
        // 340px of it to context. The breakdown is now a sheet off the
        // toolbar, so the listing gets the whole row at every size.
        <FilesPanel
            appId={id}
            initialPath={filesResult.path}
            initialFiles={filesResult.files}
            hiddenCount={filesResult.hiddenCount}
            showHidden={showHidden}
            canManage={canManage}
            breakdown={breakdown}
          />
      )}
    </div>
  );
}
