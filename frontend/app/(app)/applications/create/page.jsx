import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSiteTypes, getServerCapabilities } from "@/lib/applications/get-applications";
import { getSystemUserOptions } from "@/lib/system-users/get-system-users";
import { getGitAccounts } from "@/lib/git/get-git";
import { getPhp } from "@/lib/php/get-php";
import { getNode } from "@/lib/node/get-node";
import { getTimezones } from "@/lib/settings/get-timezones";
import { getEngines } from "@/lib/databases/get-databases";
import {
  engineInstalling,
  noDatabaseEngine,
} from "@/lib/applications/database-readiness";
import { NoDatabaseEngineNotice } from "@/components/applications/no-database-engine-notice";
import { CreateApplicationForm } from "@/components/applications/create-application-form";
import { LoadFailed } from "@/components/data-table/load-failed";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("applications");
  return { title: t("createTitle") };
}

export default async function CreateApplicationPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, types, systemUsers, accounts, php, node, capabilities, timezones, engines] = await Promise.all([
    getPermissions(),
    getTranslations("applications"),
    getSiteTypes(),
    getSystemUserOptions(),
    getGitAccounts(),
    getPhp(),
    getNode(),
    // For the temporary-domain option: the server names both the address to
    // point at and the wildcard-DNS hosts it will answer for.
    getServerCapabilities().catch(() => null),
    getTimezones().catch(() => []),
    // Cheap and cached, and the only way to answer "will a WordPress install
    // actually work here" before someone spends a minute filling this in.
    getEngines().catch(() => ({ engines: [], failed: true })),
  ]);

  const phpVersions = (php.data?.versions ?? []).filter((version) => !version.status || version.status === "ready");
  // The system Node is only worth offering when the panel does not already
  // manage that number. Listing it twice put two options with the SAME value in
  // the picker, and Radix renders every matching item's text into the trigger —
  // which is where "24.19.024.19.0" came from.
  const managedNode = (node.data?.versions ?? []).filter(
    (version) => !version.status || version.status === "ready",
  );
  const systemNode =
    node.data?.system && !managedNode.some((v) => v.version === node.data.system.version)
      ? [{ ...node.data.system, status: "ready" }]
      : [];
  const nodeVersions = [...managedNode, ...systemNode];

  if (!can(permissions, "application", "manage")) redirect("/applications");

  if (types.failed) return <LoadFailed description={t("loadFailed")} status={types.status} failure={types.failure} />;

  // Only a type the server actually offers. A query parameter is somebody
  // else's input, and a made-up one would seed the form with a site type that
  // does not exist.
  const prefillType = types.siteTypes.some((type) => type.name === sp?.type)
    ? sp.type
    : "";

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("createTitle")}</h1>
        <p className="text-sm text-muted-foreground">{t("createSubtitle")}</p>
      </div>
      {/* A precondition of the server, not a field of the form, so it sits
          above it rather than in the readiness checklist — that list focuses
          the input it names, and there is no input for this. */}
      {noDatabaseEngine(engines) ? (
        <NoDatabaseEngineNotice installing={engineInstalling(engines)} />
      ) : null}

      <CreateApplicationForm
        // Prefilled from the URL, and only ever with a type the server
        // actually offers — a query parameter is somebody else's input, and a
        // made-up one would seed the form with a site type that does not exist.
        initialType={prefillType}
        // Same value as the name: a marketplace type's slug is a valid site
        // name, and it saves the one field that has to be filled before the
        // temporary domain can be generated from it.
        initialName={prefillType}
        siteTypes={types.siteTypes}
        systemUsers={systemUsers.users}
        systemUsersFailed={systemUsers.failed}
        canCreateSystemUser={can(permissions, "system_user", "manage")}
        gitAccounts={accounts.accounts}
        gitAccountsFailed={accounts.failed}
        phpVersions={phpVersions}
        phpDefaultVersion={php.data?.default ?? null}
        phpVersionsFailed={php.failed}
        nodeVersions={nodeVersions}
        nodeDefaultVersion={node.data?.default ?? null}
        nodeVersionsFailed={node.failed}
        serverIp={capabilities?.serverIp ?? null}
        temporaryDomainSuffixes={capabilities?.temporaryDomainSuffixes ?? []}
        timezones={timezones ?? []}
      />
    </div>
  );
}
