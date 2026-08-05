import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { ArrowLeft, ExternalLink } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import {
  getApplicationDomains,
  getApplicationCertificate,
} from "@/lib/applications/get-application-domains";
import { ProvisioningCard } from "@/components/applications/provisioning-card";
import { ApplicationRowActions } from "@/components/applications/application-row-actions";
import { SiteFactsCard } from "@/components/applications/site-facts-card";
import { SourceCard } from "@/components/applications/source-card";
import { ProcessCard } from "@/components/applications/process-card";
import { DomainsCard } from "@/components/applications/domains-card";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";

export const dynamic = "force-dynamic";

const STATUS_VARIANTS = {
  active: "success",
  failed: "destructive",
  provisioning: "warning",
  pending: "secondary",
};

export async function generateMetadata({ params }) {
  const { application } = await params;
  const result = await getApplication(application);
  return { title: result.application?.name ?? "Application" };
}

export default async function ApplicationDetailPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    // Application-level grants live in their own catalog; deploy and domains are
    // gated there, not by the server-level `application` permission.
    getPermissions("application", id).catch(() => []),
    getTranslations("applications"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  const canManage = can(permissions, "application", "manage");
  const canDeploy = can(appPermissions, "app_deployment", "manage", "application");
  const canSeeDomains = can(appPermissions, "app_domain", "view", "application");
  const isGit = Boolean(application.repository || application.repository_url);
  // Only a serving site has domains, a certificate or a running process. While
  // it is still being built, saying anything about them would be invention.
  const settled = application.status === "active";

  const [domainList, certificate] = await Promise.all([
    settled && canSeeDomains
      ? getApplicationDomains(id)
      : Promise.resolve({ domains: [], failed: false }),
    settled && canSeeDomains
      ? getApplicationCertificate(id)
      : Promise.resolve({ certificate: null, failed: false }),
  ]);

  // Link over https only when a certificate is actually serving — otherwise
  // https lands on a browser security warning; the site is still on http.
  const secured = certificate.certificate?.status === "active";
  const siteUrl = `${secured ? "https" : "http"}://${application.domain}`;

  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <Button asChild variant="ghost" size="sm" className="-ml-2">
          <Link href="/applications">
            <ArrowLeft className="size-4" />
            {t("back")}
          </Link>
        </Button>

        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-2xl font-semibold tracking-tight">{application.name}</h1>
              <Badge
                variant={STATUS_VARIANTS[application.status] ?? "secondary"}
                className="font-normal"
              >
                {application.status_title ?? application.status}
              </Badge>
              <Badge variant="secondary" className="font-normal">
                {application.site_type_title ?? application.site_type}
              </Badge>
            </div>
            <div className="flex items-center gap-1">
              {application.status === "active" ? (
                <a
                  href={siteUrl}
                  target="_blank"
                  rel="noreferrer"
                  className="font-mono text-sm text-primary underline-offset-4 hover:underline"
                >
                  {application.domain}
                </a>
              ) : (
                <span className="font-mono text-sm text-muted-foreground">
                  {application.domain}
                </span>
              )}
              <CopyButton value={application.domain} />
            </div>
          </div>

          <div className="flex items-center gap-2">
            {application.status === "active" ? (
              <Button asChild variant="outline" size="sm">
                <a href={siteUrl} target="_blank" rel="noreferrer">
                  <ExternalLink className="size-4" />
                  {t("actions.visit")}
                </a>
              </Button>
            ) : null}
            <ApplicationRowActions
              application={application}
              canManage={canManage}
              showNavigation={false}
            />
          </div>
        </div>
      </div>

      {/* Until it is serving, the provisioning card IS the page: cards about a
          web root, a certificate and a process that do not exist yet are worse
          than nothing. */}
      {!settled ? (
        <ProvisioningCard application={application} canManage={canManage} />
      ) : (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
          <div className="space-y-6">
            <SiteFactsCard application={application} />
            {isGit ? <SourceCard application={application} canDeploy={canDeploy} /> : null}
          </div>
          <div className="space-y-6">
            {canSeeDomains ? (
              <DomainsCard
                application={application}
                domains={domainList.domains}
                certificate={certificate.certificate}
                failed={domainList.failed || certificate.failed}
                href={`/applications/${id}/domains`}
              />
            ) : null}
            {application.has_process ? (
              <ProcessCard application={application} canManage={canManage} />
            ) : null}
          </div>
        </div>
      )}
    </div>
  );
}
