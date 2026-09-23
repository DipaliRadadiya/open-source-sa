import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getRoles } from "@/lib/roles/get-roles";
import { getPermissionCatalog } from "@/lib/permissions/get-permission-catalog";
import { RoleForm } from "@/components/admin/roles/role-form";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";

export const dynamic = "force-dynamic";

// No GET-single-role endpoint exists, so fetch the (small) full list and match.
export default async function EditRolePage({ params }) {
  const { role: roleId } = await params;
  const [{ roles, failed, status, failure, message }, catalog, t] = await Promise.all([
    getRoles(),
    getPermissionCatalog(),
    getTranslations("roles"),
  ]);

  // A failed fetch is not a missing role. Matching an id against an empty list
  // and calling notFound() states, with a 404, that something exists nowhere —
  // on the evidence of one request that did not come back.
  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const role = roles.find((r) => String(r.id) === String(roleId));
  if (!role) notFound();
  // System roles (Administrator) can't be edited — the backend rejects it.
  if (role.is_system) redirect("/admin/roles");

  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader title={t("form.editTitle")} subtitle={t("form.editSubtitle")} />
      <RoleForm mode="edit" role={role} catalog={catalog} />
    </div>
  );
}
