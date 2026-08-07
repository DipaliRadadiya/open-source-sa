import { getTranslations } from "next-intl/server";
import { getRestores } from "@/lib/backups/get-backups";
import { RestoresList } from "@/components/backups/restores-list";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

/**
 * Every restore this server has run.
 *
 * No permission check of its own: the layout already gates the whole section
 * on `backup,view`, and anyone who may see which backups exist may see which
 * of them were put back over a live site — arguably more so.
 */
export default async function RestoresPage({ searchParams }) {
  const sp = await searchParams;
  const [{ restores, meta, failed }, t] = await Promise.all([
    getRestores(sp),
    getTranslations("backups"),
  ]);

  if (failed) return <LoadFailed description={t("loadFailed")} />;

  return (
    <NavTransitionProvider>
      <div className="space-y-4">
        <RestoresList restores={restores} />
        {restores.length > 0 ? <DataTablePagination meta={meta} /> : null}
      </div>
    </NavTransitionProvider>
  );
}
