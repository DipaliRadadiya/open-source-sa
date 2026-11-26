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
import { getApplicationCompose } from "@/lib/applications/get-application-compose";
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

  if (!can(appPermissions, "app_container", "view", "application")) {
    return <PermissionDenied title={t("title")} typeFeature />;
  }

  const application = result.application;
  const canManage = can(
    appPermissions,
    "app_container",
    "manage",
    "application",
  );

  const [networks, volumes, registries, limits] =
    application.status === "active"
      ? await Promise.all([
          getDockerNetworks(),
          getDockerVolumes(),
          getRegistries(),
          getDockerLimits(),
        ])
      : [[], [], [], {}];

  const compose = can(appPermissions, "app_compose", "view", "application")
    ? await getApplicationCompose(id)
    : null;
  const ownCompose = Boolean(compose && !compose.failed && compose.generated === false);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />
      <ContainerCard
        application={application}
        networks={networks}
        volumes={volumes}
        registries={registries}
        limits={limits}
        canManage={canManage}
        ownCompose={ownCompose}
      />
    </div>
  );
}
