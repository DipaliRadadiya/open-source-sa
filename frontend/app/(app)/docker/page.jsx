import { getTranslations } from "next-intl/server";
import { Container } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getDockerResources } from "@/lib/docker/get-docker";
import { DockerResourcesPanel } from "@/components/docker/docker-resources-panel";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("docker");
  return { title: t("title") };
}

export default async function DockerPage() {
  const [permissions, t] = await Promise.all([
    getPermissions(),
    getTranslations("docker"),
  ]);

  if (!can(permissions, "docker", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "docker", "manage");

  const { networks, volumes, failed, status, failure, message } = await getDockerResources();

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {failed ? (
        /*
         * Not an empty table. "This server has no networks" is a claim about
         * the machine, and Docker always has at least three — reporting it
         * without having heard from the machine would be a lie that looks
         * like data.
         *
         * A 409 here is the honest case: the endpoints are gated on the
         * server hosting containers, so a LEMP box says so rather than
         * shelling out to a docker binary that is not installed.
         */
        status === 409 ? (
          <EmptyState icon={Container} title={t("unavailable.title")} description={t("unavailable.body")} />
        ) : (
          <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />
        )
      ) : (
        <DockerResourcesPanel
          initialNetworks={networks}
          initialVolumes={volumes}
          canManage={canManage}
        />
      )}
    </div>
  );
}
