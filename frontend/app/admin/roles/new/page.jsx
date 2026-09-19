import { getTranslations } from "next-intl/server";
import { getPermissionCatalog } from "@/lib/permissions/get-permission-catalog";
import { RoleForm } from "@/components/admin/roles/role-form";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

export default async function NewRolePage() {
  const [catalog, t] = await Promise.all([
    getPermissionCatalog(),
    getTranslations("roles"),
  ]);

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader title={t("form.createTitle")} subtitle={t("form.createSubtitle")} />
      <RoleForm mode="create" catalog={catalog} />
    </div>
  );
}
