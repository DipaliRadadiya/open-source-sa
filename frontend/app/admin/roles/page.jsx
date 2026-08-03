import { getTranslations } from "next-intl/server";
import { getRoles } from "@/lib/roles/get-roles";
import { RolesTable } from "@/components/admin/roles/roles-table";

export const dynamic = "force-dynamic";

export default async function AdminRolesPage() {
  const [roles, t] = await Promise.all([getRoles(), getTranslations("roles")]);

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>
      <RolesTable data={roles} />
    </div>
  );
}
