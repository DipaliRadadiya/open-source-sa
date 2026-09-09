import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getLatestSyncRun, getSyncIgnores, getSyncRunItems } from "@/lib/server/get-sync";
import { SyncPanel } from "@/components/sync/sync-panel";
import { serverSnapshot } from "@/lib/sync/server-snapshot";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("sync");
  return { title: t("title") };
}

export default async function SyncPage() {
  const [permissions, t] = await Promise.all([getPermissions(), getTranslations("sync")]);

  if (!can(permissions, "sync", "view")) redirect("/dashboard");

  const canManage = can(permissions, "sync", "manage");

  const [latest, ignoreList] = await Promise.all([getLatestSyncRun(), getSyncIgnores()]);

  if (latest.failed) {
    return (
      <div className="space-y-6">
        <Header t={t} />
        <LoadFailed status={latest.status} failure={latest.failure} />
      </div>
    );
  }

  /* `sync: null` is a server nobody has ever scanned — the feature's normal
     first state, not a failure. It must not be rendered as one. */
  const run = latest.data?.sync ?? null;

  /* GET /server/sync/latest deliberately does not load the items relation, so
     a run reopened after a refresh arrives with counts and no rows. Fetching
     the first page here keeps the list server-rendered like every other list
     in the panel; the client drains the rest only if there is more. */
  const first = run ? await getSyncRunItems(run.id) : null;
  const items = first?.data?.sync?.items ?? [];
  const ignores = ignoreList.data?.ignores ?? [];

  return (
    <div className="space-y-6">
      <Header t={t} />
      {/* Keyed on the data, so Refresh actually shows what it fetched. The
          panel seeds its state from these props once and then owns them —
          a scan streams rows in by polling — so without a key it kept the
          copy it mounted with and the button spun over a real round trip
          for nothing. An unchanged server render produces the same key and
          changes nothing. */}
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
    <div className="space-y-1">
      <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
      <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
    </div>
  );
}
