import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { ArrowLeft } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getApplication } from "@/lib/applications/get-applications";
import {
  getApplicationDomains,
  getApplicationCertificate,
} from "@/lib/applications/get-application-domains";
import { getServerFacts } from "@/lib/server/get-server-facts";
import { DomainsSection } from "@/components/applications/domains/domains-section";
import { SslSection } from "@/components/applications/domains/ssl-section";
import { DomainsSslTabs } from "@/components/applications/domains/domains-ssl-tabs";
import { LoadFailed } from "@/components/data-table/load-failed";
import { Button } from "@/components/ui/button";

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

  const [domainList, certificate, facts] = await Promise.all([
    settled ? getApplicationDomains(id) : Promise.resolve({ domains: [], failed: false }),
    settled ? getApplicationCertificate(id) : Promise.resolve({ certificate: null, failed: false }),
    // The A-record target for unverified domains. Null for a role that can't
    // read server facts — the UI falls back to generic guidance.
    settled ? getServerFacts() : Promise.resolve(null),
  ]);

  const certifiable = domainList.domains.some((d) => d.certifiable);
  const serverIp = facts?.ip ?? null;

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
      <div className="space-y-3">
        <Button asChild variant="ghost" size="sm" className="-ml-2">
          <Link href="/applications">
            <ArrowLeft className="size-4" />
            {t("back")}
          </Link>
        </Button>
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold tracking-tight">{t("pageTitle")}</h1>
          <p className="text-sm text-muted-foreground">{t("pageSubtitle")}</p>
        </div>
      </div>

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
              canManage={canManage}
            />
          }
        />
      )}
    </div>
  );
}
