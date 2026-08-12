import { PageHeader } from "@/components/ui/page-header";
import { notFound, redirect } from "next/navigation";
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

  if (!can(permissions, "application", "view")) redirect("/dashboard");
  if (result.status === 404) notFound();
  if (result.failed || !result.application) return <LoadFailed description={t("loadFailed")} />;

  const application = result.application;
  if (!can(appPermissions, "app_domain", "view", "application")) redirect(`/applications/${id}`);
  const canManage = can(appPermissions, "app_domain", "manage", "application");
  const settled = application.status === "active";

  const [domainList, certificate, capabilities] = await Promise.all([
    settled ? getApplicationDomains(id) : Promise.resolve({ domains: [], failed: false }),
    settled ? getApplicationCertificate(id) : Promise.resolve({ certificate: null, availableTypes: [], failed: false }),
    // The A-record target for unverified domains. The server states its own
    // address; null (a role that cannot read it) falls back to generic guidance.
    settled ? getServerCapabilities().catch(() => null) : Promise.resolve(null),
  ]);

  // What the SITE can be issued, per the server — not what its domains resolve
  // to. A nip.io or internal name cannot get Let's Encrypt but can absolutely
  // have a self-signed certificate, and deriving this from the domains alone
  // told those sites they could have no SSL at all.
  const availableTypes = certificate.availableTypes ?? [];
  const certifiable = availableTypes.length
    ? availableTypes.some((entry) => entry.available)
    : domainList.domains.some((d) => d.certifiable);
  const serverIp = capabilities?.serverIp ?? null;

  const cert = certificate.certificate;
  const sslStatus = !cert
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
      <PageHeader
        title={t("pageTitle")}
        subtitle={t("pageSubtitle")}
      />

      {!settled ? (
        <div className="rounded-2xl border bg-muted/30 p-6 text-sm text-muted-foreground">
          {t("provisioning")}
        </div>
      ) : domainList.failed ? (
        <LoadFailed description={t("loadFailed")} />
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
            />
          }
          ssl={
            <SslSection
              appId={id}
              initialCertificate={certificate.certificate}
              certifiable={certifiable}
              availableTypes={availableTypes}
              canManage={canManage}
            />
          }
        />
      )}
    </div>
  );
}
