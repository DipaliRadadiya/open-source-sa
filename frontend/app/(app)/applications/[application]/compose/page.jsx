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

/**
 * A container site's compose file, on its own screen.
 *
 * It was a dialog opened from the Dashboard's Container card, which was wrong
 * twice: this file *is* the site — its image, ports, volumes and environment
 * variables are all in it — and a sixty-line YAML box in a modal put the Save
 * button below the fold on a desktop, so the control you came for was the one you
 * could not see.
 *
 * A page fixes the second problem by not having it: the editor is as wide as the
 * screen and the save control sits in normal document flow, where nothing can push
 * it off.
 *
 * It sits where Environment would for any other site type, and that is the point —
 * a container gets no Environment screen because its variables live here instead.
 */
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

  // The site is gone. Land on the list — the only place left to go — and say why
  // on arrival, rather than parking on a dead end that offers one link.
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

  // Granted only for site types that HAVE a compose file, so a missing grant here
  // means this screen should not exist for this site — a PHP site reaching it by
  // URL is told no rather than shown an empty editor.
  if (!can(appPermissions, "app_compose", "view", "application")) {
    return <PermissionDenied title={t("title")} />;
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
      <PageHeader title={t("title")} subtitle={t("hint")} />
      <ComposeEditor
        application={result.application}
        canManage={canManage}
        initialCompose={file.compose}
        initialGenerated={file.generated}
      />
    </div>
  );
}
