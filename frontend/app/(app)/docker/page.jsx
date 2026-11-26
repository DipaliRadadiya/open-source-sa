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

  // Container sites that are serving, for attaching one here (attaching rewrites the
  // compose file and restarts the site). Gated on `application` manage, not `docker`.
  const canManageSites = can(permissions, "application", "manage");
  // Names for the "belongs to" badges; anyone who can see applications gets them.
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
  // Not one that runs its own compose file (a 1-Click Recipe, or one taken over):
  // the mount would be stored and answered 200, and the container never gets it.
  const composes = await Promise.all(serving.map((application) => getApplicationCompose(application.id)));
  const sites = serving.filter((_, index) => !(composes[index].failed === false && composes[index].generated === false));

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />

      {failed ? (
        /* A failed read is not an empty table. 409: this server doesn't host containers. */
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
        /* Networks and volumes only; registry credentials live under Integrations. */
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
