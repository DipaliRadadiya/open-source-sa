import { getTranslations } from "next-intl/server";
import { Container } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getDockerResources } from "@/lib/docker/get-docker";
import { getAllApplications } from "@/lib/applications/get-applications";
import { getApplicationCompose } from "@/lib/applications/get-application-compose";
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

  if (!can(permissions, "docker", "view"))
    return <PermissionDenied title={t("title")} feature="docker" />;
  const canManage = can(permissions, "docker", "manage");

  const { networks, volumes, failed, status, failure, message } =
    await getDockerResources();

  const canManageSites = can(permissions, "application", "manage");
  const allApplications = can(permissions, "application", "view")
    ? (await getAllApplications()).applications
    : [];
  const applicationNames = Object.fromEntries(allApplications.map((application) => [application.id, application.name]));
  const serving = canManageSites
    ? allApplications.filter(
        (application) =>
          application.serving_profile === "docker" &&
          application.status === "active",
      )
    : [];
  // The API would accept a mount for a site running its own compose file, but the container never gets it.
  const composes = await Promise.all(serving.map((application) => getApplicationCompose(application.id)));
  const sites = serving.filter((_, index) => !(composes[index].failed === false && composes[index].generated === false));

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />

      {failed ? (
        status === 409 ? (
          <EmptyState
            icon={Container}
            title={t("unavailable.title")}
            description={t("unavailable.body")}
          />
        ) : (
          <LoadFailed
            description={t("loadFailed")}
            status={status}
            failure={failure}
            message={message}
          />
        )
      ) : (
        <DockerResourcesPanel
          initialNetworks={networks}
          initialVolumes={volumes}
          sites={sites}
          applicationNames={applicationNames}
          canManage={canManage}
          canManageSites={canManageSites}
        />
      )}
    </div>
  );
}
