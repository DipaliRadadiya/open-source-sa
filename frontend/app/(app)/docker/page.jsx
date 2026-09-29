import { getTranslations } from "next-intl/server";
import { Container } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getDockerResources } from "@/lib/docker/get-docker";
import { getAllApplications } from "@/lib/applications/get-applications";
import { DockerResourcesPanel } from "@/components/docker/docker-resources-panel";
import { RegistriesCard } from "@/components/docker/registries-card";
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
    return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "docker", "manage");

  const { networks, volumes, registries, failed, status, failure, message } =
    await getDockerResources();

  // The container sites, for attaching one from this page. Only sites that are
  // actually serving: the endpoint applies the change by rewriting the compose
  // file and bringing the container up, which on a pending site would be
  // provisioning it as a side effect of a click on a different screen.
  //
  // Gated on `application` manage, not `docker` manage: attaching writes the
  // SITE's configuration and restarts it, so the permission that governs it is
  // the one that governs the site.
  const canManageSites = can(permissions, "application", "manage");
  const sites = canManageSites
    ? (await getAllApplications()).applications.filter(
        (application) =>
          application.serving_profile === "docker" &&
          application.status === "active",
      )
    : [];

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
        <>
          <DockerResourcesPanel
            initialNetworks={networks}
            initialVolumes={volumes}
            sites={sites}
            canManage={canManage}
            canManageSites={canManageSites}
          />
          {/* Below the two Docker objects rather than above them, because it is
              the one most servers will never configure — every public image and
              all fifteen one-click apps need nothing here. */}
          <RegistriesCard
            initialRegistries={registries}
            canManage={canManage}
          />
        </>
      )}
    </div>
  );
}
