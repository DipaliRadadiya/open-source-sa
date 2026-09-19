import { getTranslations } from "next-intl/server";
import { getRolesPage } from "@/lib/roles/get-roles";
import { RolesTable } from "@/components/admin/roles/roles-table";
import { redirectOutOfRange } from "@/lib/tables/redirect-out-of-range";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export default async function AdminRolesPage({ searchParams }) {
  const sp = await searchParams;
  const query = new URLSearchParams(
    Object.entries(sp ?? {}).filter(([, v]) => typeof v === "string"),
  ).toString();

  const [rolesPage, t] = await Promise.all([getRolesPage(query), getTranslations("roles")]);


  // Before anything renders: a page past the end sends the reader to the
  // last real page instead of painting an error for it.
  redirectOutOfRange("/admin/roles", sp, rolesPage.meta, rolesPage.failed);
  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />
      {/* "No roles yet" is a statement about this panel, and a failed request
          is not evidence for it — every sibling admin table already branches
          here. `failed` was being passed to redirectOutOfRange one line above
          and then dropped for rendering. */}
      {rolesPage.failed ? (
        <LoadFailed
          description={t("loadFailed")}
          status={rolesPage.status}
          failure={rolesPage.failure}
        />
      ) : (
        <RolesTable data={rolesPage.roles} meta={rolesPage.meta} />
      )}
    </div>
  );
}
