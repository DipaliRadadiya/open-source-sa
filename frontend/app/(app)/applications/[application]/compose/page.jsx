import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getApplicationCompose } from "@/lib/applications/get-application-compose";
import { ComposeEditor } from "@/components/applications/compose-editor";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.compose"),
    getApplication(application),
  ]);

  return { title: `${t("title")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationComposePage({ params }) {
  const { application: id } = await params;

  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.compose"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view"))
    return <PermissionDenied title={t("title")} />;

  if (result.status === 404) redirect("/applications?gone=1");

  if (result.failed || !result.application) {
    return (
      <LoadFailed
        description={t("loadFailed")}
        status={result.status}
        failure={result.failure}
        message={result.message}
      />
    );
  }

  if (!can(appPermissions, "app_compose", "view", "application")) {
    return <PermissionDenied title={t("title")} typeFeature />;
  }

  const canManage = can(appPermissions, "app_compose", "manage", "application");
  const file = await getApplicationCompose(id);

  if (file.failed) {
    return (
      <LoadFailed
        description={t("loadFailed")}
        status={file.status}
        failure={file.failure}
        message={file.message}
      />
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} />
      <ComposeEditor
        application={result.application}
        canManage={canManage}
        initialCompose={file.compose}
        initialGenerated={file.generated}
      />
    </div>
  );
}
