import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getLatestSyncRun, getSyncIgnores, getSyncRunItems } from "@/lib/server/get-sync";
import { SyncPanel } from "@/components/sync/sync-panel";
import { serverSnapshot } from "@/lib/sync/server-snapshot";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("sync");
  return { title: t("title") };
}

export default async function SyncPage() {
  const [permissions, t] = await Promise.all([getPermissions(), getTranslations("sync")]);

  if (!can(permissions, "sync", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "sync", "manage");

  const [latest, ignoreList] = await Promise.all([getLatestSyncRun(), getSyncIgnores()]);

  if (latest.failed) {
    return (
      <div className="space-y-6">
        <Header t={t} />
        <LoadFailed status={latest.status} failure={latest.failure} message={latest.message} debug={latest.debug} />
      </div>
    );
  }

  /* `sync: null` means never scanned: a normal state, not a failure. */
  const run = latest.data?.sync ?? null;

  /* /server/sync/latest omits items, so the first page is fetched here; the
     client loads the rest. */
  const first = run ? await getSyncRunItems(run.id) : null;
  const items = first?.data?.sync?.items ?? [];
  const ignores = ignoreList.data?.ignores ?? [];

  return (
    <div className="space-y-6">
      <Header t={t} />
      {/* Keyed on the data: the panel seeds state from props once, so a
          refresh must remount it. */}
      <SyncPanel
        key={serverSnapshot(run, items, ignores)}
        run={run}
        items={items}
        ignores={ignores}
        canManage={canManage}
      />
    </div>
  );
}

function Header({ t }) {
  return (
    <PageHeader title={t("title")} subtitle={t("subtitle")} />
  );
}
