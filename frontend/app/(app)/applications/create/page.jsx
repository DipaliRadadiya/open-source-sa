import { getTranslations, getFormatter } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getSiteTypes, getServerCapabilities } from "@/lib/applications/get-applications";
import { getSystemUserOptions } from "@/lib/system-users/get-system-users";
import { getGitAccounts } from "@/lib/git/get-git";
import { getPhp } from "@/lib/php/get-php";
import { getNode } from "@/lib/node/get-node";
import { getTimezones } from "@/lib/settings/get-timezones";
import { getEngines } from "@/lib/databases/get-databases";
import { engineInstalling, noDatabaseEngine } from "@/lib/applications/database-readiness";
import { withAvailability } from "@/lib/applications/blockers";
import { NoDatabaseEngineNotice } from "@/components/applications/no-database-engine-notice";
import { CreateApplicationForm } from "@/components/applications/create-application-form";
import { LoadFailed } from "@/components/data-table/load-failed";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("applications");
  return { title: t("createTitle") };
}

export default async function CreateApplicationPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, tEngines, format, types, systemUsers, accounts, php, node, capabilities, timezones, engines] = await Promise.all([
    getPermissions(),
    getTranslations("applications"),
    // Reuses the databases page labels so the two cannot drift.
    getTranslations("databases.engines"),
    getFormatter(),
    getSiteTypes(),
    getSystemUserOptions(),
    getGitAccounts(),
    getPhp(),
    getNode(),
    // Temporary-domain option: target address and wildcard-DNS hosts.
    getServerCapabilities().catch(() => null),
    getTimezones().catch(() => []),
    // Cheap and cached; tells whether a WordPress install can work here.
    getEngines().catch(() => ({ engines: [], failed: true })),
  ]);

  const phpVersions = (php.data?.versions ?? []).filter((version) => !version.status || version.status === "ready");
  // Offer the system Node only when the panel does not already manage that
  // version: duplicate values make Radix render every match into the trigger.
  const managedNode = (node.data?.versions ?? []).filter(
    (version) => !version.status || version.status === "ready",
  );
  const systemNode =
    node.data?.system && !managedNode.some((v) => v.version === node.data.system.version)
      ? [{ ...node.data.system, status: "ready" }]
      : [];
  const nodeVersions = [...managedNode, ...systemNode];

  // Explain instead of redirecting, so a view-only reader learns why.
  if (!can(permissions, "application", "manage")) {
    return <PermissionDenied title={t("createTitle")} description={t("noPermission")} />;
  }

  if (types.failed) return <LoadFailed description={t("loadFailed")} status={types.status} failure={types.failure} message={types.message} debug={types.debug} />;

  // Availability is marked here so the prefill below reads the same
  // `available` the grid uses. One pass over every check, so all missing
  // requirements are reported together.
  const siteTypes = withAvailability(
    types.siteTypes,
    {
      runtimes: {
        phpVersions,
        nodeVersions,
        // The versions the PHP and Node pages actually offer, so a blocked
        // card names an installable version rather than a range.
        phpInstallable: php.data?.installable ?? [],
        nodeInstallable: node.data?.installable ?? [],
        failed: php.failed || node.failed,
      },
      engines,
    },
    (block) => {
      if (block.kind === "runtime") {
        const key = block.suggest ? "install" : "none";
        return t(`unavailableRuntime.${block.runtime}.${key}`, {
          range: block.label,
          installed: format.list(block.installed, { type: "conjunction" }),
          suggest: block.suggest ?? "",
        });
      }

      return t(`unavailableDatabase.${block.state}`, {
        // `t.has`: an engine without a label prints its own name instead of throwing.
        engines: format.list(
          block.engines.map((engine) => (tEngines.has(engine) ? tEngines(engine) : engine)),
          { type: "disjunction" },
        ),
      });
    },
  );

  // Query parameters are untrusted: only prefill a type the server offers
  // and can create.
  const prefillType = siteTypes.some((type) => type.name === sp?.type && type.available)
    ? sp.type
    : "";

  /*
   * The account the Git page just connected, checked against the real list.
   * An unknown id is dropped, since it would seed a picker that cannot submit.
   */
  const prefillGitAccount = (accounts.accounts ?? []).some(
    (account) => String(account.id) === String(sp?.git_account),
  )
    ? String(sp.git_account)
    : "";

  return (
    <div className="space-y-6">
      <PageHeader title={t("createTitle")} subtitle={t("createSubtitle")} />
      {/* A server precondition, not a form field, so it sits above the form
          rather than in the readiness checklist (which focuses inputs). */}
      {noDatabaseEngine(engines) ? (
        <NoDatabaseEngineNotice installing={engineInstalling(engines)} />
      ) : null}

      <CreateApplicationForm
        initialType={prefillType}
        // A marketplace slug is a valid site name, and the temporary domain
        // is generated from the name.
        initialName={prefillType}
        siteTypes={siteTypes}
        systemUsers={systemUsers.users}
        systemUsersFailed={systemUsers.failed}
        canCreateSystemUser={can(permissions, "system_user", "manage")}
        initialGitAccountId={prefillGitAccount}
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
        // For the required-services panel: what is missing and what can be installed.
        engines={engines?.engines ?? []}
        // Unfiltered: `phpVersions` drops non-ready versions, including the one installing.
        phpVersionsAll={php.data?.versions ?? []}
        nodeVersionsAll={node.data?.versions ?? []}
        phpInstallable={php.data?.installable ?? []}
        nodeInstallable={node.data?.installable ?? []}
        canInstall={{
          php: can(permissions, "php", "manage"),
          node: can(permissions, "node", "manage"),
          database: can(permissions, "database", "manage"),
        }}
      />
    </div>
  );
}
