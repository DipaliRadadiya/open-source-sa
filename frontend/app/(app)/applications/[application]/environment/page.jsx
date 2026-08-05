import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { ArrowLeft } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import { getApplicationEnvironment } from "@/lib/applications/get-application-environment";
import { EnvironmentEditor } from "@/components/applications/environment/environment-editor";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Button } from "@/components/ui/button";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.environment"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationEnvironmentPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.environment"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application)
    return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  // The permission is only granted for site types that actually keep a .env, so
  // a missing grant here means the screen shouldn't exist for this site.
  if (!can(appPermissions, "app_environment", "view", "application")) {
    redirect(`/applications/${id}`);
  }
  const canManage = can(
    appPermissions,
    "app_environment",
    "manage",
    "application",
  );
  const settled = application.status === "active";

  const envResult = settled
    ? await getApplicationEnvironment(id)
    : { environment: null, failed: false };

  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <Button asChild variant="ghost" size="sm" className="-ml-2">
          <Link href="/applications">
            <ArrowLeft className="size-4" />
            {t("back")}
          </Link>
        </Button>
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">
            {t("pageTitle")}
          </h1>
          <p className="text-sm text-muted-foreground">{t("pageSubtitle")}</p>
        </div>
      </div>

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : envResult.failed || !envResult.environment ? (
        <LoadFailed description={t("loadFailed")} />
      ) : (
        <EnvironmentEditor
          appId={id}
          initialEnv={envResult.environment}
          canManage={canManage}
        />
      )}
    </div>
  );
}
