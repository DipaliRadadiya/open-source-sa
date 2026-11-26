import { PageHeader } from "@/components/ui/page-header";
import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication, getServerCapabilities } from "@/lib/applications/get-applications";
import {
  getApplicationDomains,
  getApplicationCertificate,
} from "@/lib/applications/get-application-domains";
import { DomainsSection } from "@/components/applications/domains/domains-section";
import { SslSection } from "@/components/applications/domains/ssl-section";
import { DomainsSslTabs } from "@/components/applications/domains/domains-ssl-tabs";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PermissionDenied } from "@/components/sections/permission-denied";
import { isSettled } from "@/lib/applications/settled";

export const dynamic = "force-dynamic";

export async function generateMetadata({ params }) {
  const { application } = await params;
  const [t, result] = await Promise.all([
    getTranslations("applications.domains"),
    getApplication(application),
  ]);
  return { title: `${t("pageTitle")} — ${result.application?.name ?? ""}` };
}

export default async function ApplicationDomainsPage({ params }) {
  const { application: id } = await params;
  const [permissions, appPermissions, t, result] = await Promise.all([
    getPermissions(),
    getPermissions("application", id).catch(() => []),
    getTranslations("applications.domains"),
    getApplication(id),
  ]);

  if (!can(permissions, "application", "view")) return <PermissionDenied title={t("pageTitle")} />;
  if (result.status === 404) redirect("/applications?gone=1");
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} status={result.status} failure={result.failure} message={result.message} debug={result.debug} />;

  const application = result.application;
  if (!can(appPermissions, "app_domain", "view", "application")) {
    return <PermissionDenied title={t("pageTitle")} />;
  }
  const canManage = can(appPermissions, "app_domain", "manage", "application");
  const settled = isSettled(application);

  const [domainList, certificate, capabilities] = await Promise.all([
    settled ? getApplicationDomains(id) : Promise.resolve({ domains: [], failed: false }),
    settled ? getApplicationCertificate(id) : Promise.resolve({ certificate: null, availableTypes: [], failed: false }),
    settled ? getServerCapabilities().catch(() => null) : Promise.resolve(null),
  ]);

  const availableTypes = certificate.availableTypes ?? [];
  const certifiable = availableTypes.length
    ? availableTypes.some((entry) => entry.available)
    : domainList.domains.some((d) => d.certifiable);
  const serverIp = capabilities?.serverIp ?? null;

  const cert = certificate.certificate;
  // A failed certificate read is not "no certificate"; a 500 would show Not secured on a live HTTPS site.
  const sslStatus = certificate.failed
    ? "unknown"
    : !cert
    ? "none"
    : cert.status === "active"
      ? "active"
      : cert.status === "pending" || cert.status === "issuing"
        ? "issuing"
        : cert.status === "failed"
          ? "failed"
          : "none";

  return (
    <div className="space-y-6">
      <PageHeader title={t("pageTitle")} />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : domainList.failed ? (
        <LoadFailed description={t("loadFailed")} status={domainList.status} failure={domainList.failure} message={domainList.message} debug={domainList.debug} />
      ) : (
        <DomainsSslTabs
          sslStatus={sslStatus}
          domains={
            <DomainsSection
              appId={id}
              domains={domainList.domains}
              canManage={canManage}
              serverIp={serverIp}
              secured={sslStatus === "active"}
              siteType={application.site_type}
              certificate={cert}
            />
          }
          ssl={
            certificate.failed ? (
              <LoadFailed
                status={certificate.status}
                failure={certificate.failure} message={certificate.message} debug={certificate.debug}
              />
            ) : (
              <SslSection
                appId={id}
                initialCertificate={certificate.certificate}
                certifiable={certifiable}
                availableTypes={availableTypes}
                canManage={canManage}
                webServer={capabilities?.webServer ?? null}
              />
            )
          }
        />
      )}
    </div>
  );
}
