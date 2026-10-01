import { getTranslations } from "next-intl/server";
import { Container } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import {
  getDockerDatabases,
  getDockerResources,
} from "@/lib/docker/get-docker";
import { getAllApplications } from "@/lib/applications/get-applications";
import { DockerResourcesPanel } from "@/components/docker/docker-resources-panel";
import { DockerDatabasesPanel } from "@/components/docker/docker-databases-panel";
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

  const { networks, volumes, failed, status, failure, message } =
    await getDockerResources();

  // The containerised database engines. Asked for alongside the networks and
  // volumes because they are the same kind of thing — a server-level object a site
  // connects to — and because a database wants a network, which is the card above.
  //
  // Only once the resources read succeeded: on a server that hosts no containers
  // the whole page is a 409 empty state, and a second request to be told the same
  // thing is a shell-out for nothing.
  const databases = failed
    ? { databases: [], engines: [] }
    : await getDockerDatabases();

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
        /* Networks and volumes only. Registry credentials used to be a third
           card here and now live under Integrations, beside the git accounts and
           storage destinations — a credential held somewhere else is not one of
           Docker's own objects, and burying it under two tables it had nothing to
           do with is what made it undiscoverable. */
        <>
          <DockerResourcesPanel
            initialNetworks={networks}
            initialVolumes={volumes}
            sites={sites}
            canManage={canManage}
            canManageSites={canManageSites}
          />

          {/* Below the networks and volumes deliberately: a database usually wants
              a network, and the card that makes one is above it. */}
          {databases.failed ? (
            <LoadFailed
              description={t("databases.loadFailed")}
              status={databases.status}
              failure={databases.failure}
              message={databases.message}
            />
          ) : (
            <DockerDatabasesPanel
              databases={databases.databases}
              engines={databases.engines}
              networks={networks}
              canManage={canManage}
            />
          )}
        </>
      )}
    </div>
  );
}
