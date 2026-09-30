import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import {
  getDockerLimits,
  getDockerNetworks,
  getDockerVolumes,
  getRegistries,
} from "@/lib/docker/get-docker";
import { ContainerCard } from "@/components/applications/container-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.container"),
    getApplication(application),
  ]);

  return { title: `${t("title")} — ${result.application?.name ?? ""}` };
}

/**
 * What a container site runs as.
 *
 * These controls were a full-width card at the bottom of the Dashboard, under the
 * domains and the backups — a long way from where anybody goes looking for "what is
 * this container doing", and sharing a screen with nine cards about other things.
 *
 * Structured settings here; the raw compose file is next door. Two screens because
 * they are two questions — a network chooser and three fields, versus sixty lines
 * of YAML — and because one is the other's escape hatch.
 */
export default async function ApplicationContainerPage({ params }) {
  const { application: id } = await params;

  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.container"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view"))
    return <PermissionDenied title={t("title")} />;

  // The site is gone. Land on the list — the only place left to go — and say why on
  // arrival, rather than parking on a dead end that offers one link.
  if (result.status === 404) redirect("/applications?gone=1");

  if (result.failed || !result.application) {
    return (
      <LoadFailed
        description={t("failed")}
        status={result.status}
        failure={result.failure}
        message={result.message}
      />
    );
  }

  // Granted only for container site types, so a missing grant means this screen
  // should not exist for this site rather than that the user lacks a right.
  if (!can(appPermissions, "app_container", "view", "application")) {
    return <PermissionDenied title={t("title")} />;
  }

  const application = result.application;
  const canManage = can(
    appPermissions,
    "app_container",
    "manage",
    "application",
  );

  // Only once it is serving. The Docker endpoints are gated on the same profile,
  // and asking a pending site's box for networks is a request that builds choosers
  // for controls that cannot be applied yet.
  const [networks, volumes, registries, limits] =
    application.status === "active"
      ? await Promise.all([
          getDockerNetworks(),
          getDockerVolumes(),
          getRegistries(),
          // So the CPU field can name this server's core count instead of
          // describing the rule, and the memory field can show the default it
          // falls back to. Both degrade to null rather than to a guess.
          getDockerLimits(),
        ])
      : [[], [], [], {}];

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} subtitle={t("pageSubtitle")} />
      <ContainerCard
        application={application}
        networks={networks}
        volumes={volumes}
        registries={registries}
        limits={limits}
        canManage={canManage}
      />
    </div>
  );
}
