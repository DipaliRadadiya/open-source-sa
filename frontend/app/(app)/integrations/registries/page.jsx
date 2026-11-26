import { getTranslations } from "next-intl/server";
import { Container } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getRegistriesPage } from "@/lib/docker/get-docker";
import { RegistriesCard } from "@/components/docker/registries-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("docker.registries");
  return { title: t("title") };
}

export default async function RegistriesPage() {
  const [permissions, t, list] = await Promise.all([
    getPermissions(),
    getTranslations("docker.registries"),
    getRegistriesPage(),
  ]);

  if (list.failed && list.status === 409) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("title")} />
        <EmptyState
          icon={Container}
          title={t("unavailableTitle")}
          description={t("unavailableBody")}
        />
      </div>
    );
  }
  if (!can(permissions, "registry", "view"))
    return <PermissionDenied title={t("title")} feature="registry" />;
  const canManage = can(permissions, "registry", "manage");

  if (list.failed) {
    return (
      <LoadFailed
        description={t("failed")}
        status={list.status}
        failure={list.failure}
        message={list.message}
      />
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />
      <div >
        <RegistriesCard
          initialRegistries={list.registries}
          canManage={canManage}
        />
      </div>
    </div>
  );
}
