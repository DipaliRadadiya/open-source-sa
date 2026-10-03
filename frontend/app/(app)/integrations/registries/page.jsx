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

/**
 * Registry credentials, on their own page under Integrations.
 *
 * It was a card at the bottom of the Docker screen, below networks and volumes,
 * which made the panel's only private-image support something you had to already
 * know about to find. It belongs here for the reason the section exists: this is
 * an externally-held credential the features consume, like a git account or a
 * storage destination. A network is a thing on this box; a registry login is a
 * thing somewhere else.
 */
export default async function RegistriesPage() {
  const [permissions, t, list] = await Promise.all([
    getPermissions(),
    getTranslations("docker.registries"),
    getRegistriesPage(),
  ]);

  if (!can(permissions, "registry", "view"))
    return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "registry", "manage");

  // A 409 is the honest answer on a server that hosts no containers, not a failure
  // to report: a credential for pulling images is nothing without something to pull
  // them for. The sidebar already hides this there, so this is a bookmark.
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
      <PageHeader title={t("title")} subtitle={t("subtitle")} />
      <div className="max-w-5xl">
        <RegistriesCard
          initialRegistries={list.registries}
          canManage={canManage}
        />
      </div>
    </div>
  );
}
