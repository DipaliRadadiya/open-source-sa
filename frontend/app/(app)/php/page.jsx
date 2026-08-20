import { redirect } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getPhp } from "@/lib/php/get-php";
import { getPhpExtensions } from "@/lib/php/get-php-extensions";
import { VersionBar } from "@/components/runtime/version-bar";
import { VersionSummary } from "@/components/php/version-summary";
import { InstallVersionButton } from "@/components/runtime/install-version-button";
import { ExtensionsCard } from "@/components/php/extensions-card";
import { IniEditor } from "@/components/php/ini-editor";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { RuntimeStatusNotice } from "@/components/runtime/version-status";
import { anyInFlight, RUNTIME_POLL_MS, RUNTIME_POLL_STOP_MS } from "@/lib/runtime/in-flight";
import { FileCode2 } from "lucide-react";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("php");
  return { title: t("title") };
}

export default async function PhpPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, { data, failed }] = await Promise.all([
    getPermissions(),
    getTranslations("php"),
    getPhp(),
  ]);

  // Runtimes are gated by the same permission as the rest of the server config.
  if (!can(permissions, "php", "view")) redirect("/dashboard");
  const canManage = can(permissions, "php", "manage");

  if (failed || !data) return <LoadFailed description={t("loadFailed")} />;

  const php = data;
  const versions = php?.versions ?? [];
  const lifecycleAvailable = Boolean(php?.lifecycle_available);

  // The version in the URL wins so a reload keeps your place; otherwise the
  // default, which is the one most people came to look at.
  const selected =
    versions.find((version) => version.version === sp?.version)?.version ??
    php?.default ??
    versions[0]?.version ??
    null;

  const current = versions.find((version) => version.version === selected) ?? null;

  // An install that failed or is still running has nothing on disk, so the
  // extensions endpoint 404s. Asking anyway spends a request to learn what the
  // version list already said.
  const installState = current?.status && current.status !== "ready" ? current.status : null;
  const { data: extensions } = installState
    ? { data: null }
    : await getPhpExtensions(selected);

  // An install or a purge takes minutes and finishes without telling anyone, so
  // a page rendered once sits on "Installing" until you navigate away and back.
  // That is what made a finished install look stuck. Polling only while
  // something is actually running: a settled server asks for nothing.
  const inFlight = anyInFlight(versions) || anyInFlight(extensions?.extensions ?? []);

  return (
    <div className="space-y-6">
      {inFlight ? (
        <AutoRefresh intervalMs={RUNTIME_POLL_MS} stopAfterMs={RUNTIME_POLL_STOP_MS} />
      ) : null}

      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {/* No PHP at all is a normal state on a fresh server, not an error. */}
      {versions.length === 0 ? (
        <EmptyState
          icon={FileCode2}
          title={t("empty.title")}
          description={t("empty.description")}
          // The install button lives in the version card, which doesn't exist
          // yet on a fresh server — so the empty state has to carry it, or
          // there is no way to install PHP at all.
          action={
            <InstallVersionButton
              runtime="php"
              installable={php?.installable ?? []}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            />
          }
        />
      ) : (
        <div className="max-w-5xl space-y-4">
          {/* Install sits at the end of the version chips: adding a version is
              the same decision as choosing one. With a single version there are
              no chips to sit beside, so the button stands alone — it must still
              be reachable, or a one-version server could never get a second. */}
          {versions.length > 1 ? (
            <VersionBar
              versions={versions}
              selected={selected}
              namespace="php"
              lifecycleAvailable={lifecycleAvailable}
              action={
                <InstallVersionButton
                  runtime="php"
                  installable={php?.installable ?? []}
                  canManage={canManage}
                  lifecycleAvailable={lifecycleAvailable}
                />
              }
            />
          ) : (
            <InstallVersionButton
              runtime="php"
              installable={php?.installable ?? []}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            />
          )}

          {current ? (
            /*
             * Keyed on the version for the same reason as the Node page: local
             * state seeded from props does not re-seed when the props change,
             * because React reuses the instance.
             *
             * It matters more here than the stale value it prevents. IniEditor
             * holds the *edited php.ini text* in state, along with the file it
             * was loaded from and whether the warning was acknowledged, and
             * nothing re-syncs any of it when the version changes. Being a
             * modal Dialog probably makes that unreachable today — you cannot
             * click the version bar behind it — but "probably unreachable"
             * guarding a save that writes one version's configuration into
             * another's is not a guarantee worth keeping.
             */
            <VersionSummary
              key={current.version}
              version={current}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            >
              <IniEditor
                version={selected}
                canManage={canManage}
                unavailableReason={
                  // Removing had no branch here either, so a purge in progress
                  // told you the install had failed.
                  installState === "installing"
                    ? t("versions.stillInstalling")
                    : installState === "removing"
                      ? t("versions.stillRemoving")
                      : installState
                        ? t("versions.installFailedShort")
                        : null
                }
              />
            </VersionSummary>
          ) : null}

          {/* Where the extensions card would be. Rendering nothing read as a
              broken page; the reason is a fact the version list already knows. */}
          {installState ? (
            // Shared with Node so the two pages cannot drift. This branch used
            // to be `installing ? … : failed`, so a version being REMOVED
            // announced "Install failed" — a failure that had not happened.
            <RuntimeStatusNotice version={current} versionLabel={selected} namespace="php" />
          ) : extensions ? (
            <ExtensionsCard
              version={selected}
              extensions={extensions.extensions}
              panelRequired={extensions.panel_required}
              canManage={canManage}
            />
          ) : null}
        </div>
      )}
    </div>
  );
}
