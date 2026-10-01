import { versionState } from "@/components/runtime/version-status";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getPhp } from "@/lib/php/get-php";
import { getPhpExtensions } from "@/lib/php/get-php-extensions";
import { getIonCube } from "@/lib/php/get-ioncube";
import { VersionBar } from "@/components/runtime/version-bar";
import { VersionSummary } from "@/components/php/version-summary";
import { InstallVersionButton } from "@/components/runtime/install-version-button";
import { ExtensionsCard } from "@/components/php/extensions-card";
import { IonCubeCard } from "@/components/php/ioncube-card";
import { PhpVersionTabs } from "@/components/php/version-tabs";
import { IniEditor } from "@/components/php/ini-editor";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { RuntimeStatusNotice } from "@/components/runtime/version-status";
import { anyInFlight, RUNTIME_POLL_MS, RUNTIME_POLL_STOP_MS } from "@/lib/runtime/in-flight";
import { FileCode2 } from "lucide-react";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("php");
  return { title: t("title") };
}

export default async function PhpPage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, { data, failed, status, failure, message }] = await Promise.all([
    getPermissions(),
    getTranslations("php"),
    getPhp(),
  ]);

  // Runtimes are gated by the same permission as the rest of the server config.
  if (!can(permissions, "php", "view")) return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "php", "manage");

  if (failed || !data) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />;

  const php = data;
  const versions = php?.versions ?? [];
  const lifecycleAvailable = Boolean(php?.lifecycle_available);

  // The URL's version wins so a reload keeps your place; otherwise the default.
  const selected =
    versions.find((version) => version.version === sp?.version)?.version ??
    php?.default ??
    versions[0]?.version ??
    null;

  const current = versions.find((version) => version.version === selected) ?? null;

  const installState = versionState(current);
  // Both endpoints 404 for a version that is not installed (installing, failed,
  // removing), so skip them then. Fetched in parallel.
  const [{ data: extensions }, { data: ioncube, failed: ionCubeFailed }] = installState
    ? [{ data: null }, { data: null, failed: false }]
    : await Promise.all([getPhpExtensions(selected), getIonCube(selected)]);

  // Installs and purges finish without notification, so poll only while
  // something (version, extension or ionCube) is in flight.
  const inFlight =
    anyInFlight(versions) ||
    anyInFlight(extensions?.extensions ?? []) ||
    anyInFlight([ioncube]);

  return (
    <div className="space-y-6">
      {inFlight ? (
        <AutoRefresh intervalMs={RUNTIME_POLL_MS} stopAfterMs={RUNTIME_POLL_STOP_MS} />
      ) : null}

      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* No PHP installed is a normal state, not an error. */}
      {versions.length === 0 ? (
        <EmptyState
          icon={FileCode2}
          title={t("empty.title")}
          description={t("empty.description")}
          // With no versions there is no version bar, so the empty state carries Install.
          action={
            <InstallVersionButton
              runtime="php"
              installable={php?.installable ?? []}
              installed={versions}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            />
          }
        />
      ) : (
        <div className="max-w-5xl space-y-4">
          {/* Install sits after the version chips; with one version there are no
              chips, so it stands alone but must stay reachable. */}
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
                  installed={versions}
                  canManage={canManage}
                  lifecycleAvailable={lifecycleAvailable}
                />
              }
            />
          ) : (
            <InstallVersionButton
              runtime="php"
              installable={php?.installable ?? []}
              installed={versions}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            />
          )}

          {current ? (
            /* Keyed on version: without it a save could write one version's php.ini into another's. */
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

          {/* In place of the extensions card, explain why it is unavailable. */}
          {installState ? (
            // Shared with the Node page so the two cannot drift.
            <RuntimeStatusNotice version={current} versionLabel={selected} namespace="php" />
          ) : (
            // Tabs rather than stacked: the extensions list is long enough to bury what follows.
            <PhpVersionTabs
              initial={sp?.tab}
              // Excludes built-ins, matching the card's own count.
              extensionCount={extensions?.extensions?.filter((e) => !e.builtin).length}
              ionCubeState={ioncube}
              ionCubeFailed={ionCubeFailed}
              extensions={
                /* A failed fetch renders nothing, never an empty list. */
                extensions ? (
                  <ExtensionsCard
                    version={selected}
                    extensions={extensions.extensions}
                    panelRequired={extensions.panel_required}
                    toggleSupported={extensions.toggle_supported}
                    canManage={canManage}
                  />
                ) : null
              }
              ioncube={
                /* Rendered even when its fetch failed; the card reports it. */
                <IonCubeCard
                  version={selected}
                  ioncube={ioncube}
                  failed={ionCubeFailed}
                  canManage={canManage}
                />
              }
            />
          )}
        </div>
      )}
    </div>
  );
}
