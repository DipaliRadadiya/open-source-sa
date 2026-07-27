import { getTranslations } from "next-intl/server";
import { getPermissionCatalog } from "@/lib/permissions/get-permission-catalog";
import { RoleForm } from "@/components/admin/roles/role-form";

export const dynamic = "force-dynamic";

export default async function NewRolePage() {
  const [catalog, t] = await Promise.all([
    getPermissionCatalog(),
    getTranslations("roles"),
  ]);

  return (
    <div className="max-w-3xl space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">
          {t("form.createTitle")}
        </h1>
        <p className="text-sm text-muted-foreground">{t("form.createSubtitle")}</p>
      </div>
      <RoleForm mode="create" catalog={catalog} />
    </div>
  );
}
