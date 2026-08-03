import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getRoles } from "@/lib/roles/get-roles";
import { getPermissionCatalog } from "@/lib/permissions/get-permission-catalog";
import { RoleForm } from "@/components/admin/roles/role-form";

export const dynamic = "force-dynamic";

// No GET-single-role endpoint exists, so fetch the (small) full list and match.
export default async function EditRolePage({ params }) {
  const { role: roleId } = await params;
  const [roles, catalog, t] = await Promise.all([
    getRoles(),
    getPermissionCatalog(),
    getTranslations("roles"),
  ]);

  const role = roles.find((r) => String(r.id) === String(roleId));
  if (!role) notFound();
  // System roles (Administrator) can't be edited — the backend rejects it.
  if (role.is_system) redirect("/admin/roles");

  return (
    <div className="max-w-3xl space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">
          {t("form.editTitle")}
        </h1>
        <p className="text-sm text-muted-foreground">{t("form.editSubtitle")}</p>
      </div>
      <RoleForm mode="edit" role={role} catalog={catalog} />
    </div>
  );
}
