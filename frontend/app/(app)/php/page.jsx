import { versionState } from "@/components/runtime/version-status";
import { getTranslations } from "next-intl/server";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getPhp } from "@/lib/php/get-php";
import { getPhpExtensions } from "@/lib/php/get-php-extensions";
import { getIonCube } from "@/lib/php/get-ioncube";
import { VersionsCard } from "@/components/runtime/versions-table";
import { rowGrid } from "@/lib/runtime/version-rows";
import { SettingsVersionPicker } from "@/components/php/settings-version-picker";
import { compareVersions } from "@/lib/runtime/version-range";
import { VersionSummary } from "@/components/php/version-summary";
import { InstallVersionButton } from "@/components/runtime/install-version-button";
import { ExtensionsCard } from "@/components/php/extensions-card";
import { IonCubeCard } from "@/components/php/ioncube-card";
import { PhpVersionTabs } from "@/components/php/version-tabs";
import { IniEditor } from "@/components/php/ini-editor";
import { RevealSettings } from "@/components/php/reveal-settings";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { RuntimeStatusNotice } from "@/components/runtime/version-status";
import {
  anyInFlight,
  RUNTIME_POLL_MS,
  RUNTIME_POLL_STOP_MS,
} from "@/lib/runtime/in-flight";
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
  const [permissions, t, { data, failed, status, failure, message }] =
    await Promise.all([getPermissions(), getTranslations("php"), getPhp()]);

  // Runtimes are gated by the same permission as the rest of the server config.
  if (!can(permissions, "php", "view"))
    return <PermissionDenied title={t("title")} feature="php" />;
  const canManage = can(permissions, "php", "manage");

  // A 409 is not a failure to report — it is the honest answer on a Docker box,
  // whose stack serves no PHP sites. The sidebar already stops offering this
  // screen there, so this is reached by a bookmark or a tab left open across a
  // stack change, and both deserve a sentence rather than a red error card.
  if (failed && status === 409) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("title")} />
        <EmptyState
          icon={FileCode2}
          title={t("unavailable.title")}
          description={t("unavailable.body")}
        />
      </div>
    );
  }

  if (failed || !data)
    return (
      <LoadFailed
        description={t("loadFailed")}
        status={status}
        failure={failure}
        message={message}
      />
    );

  const php = data;
  const versions = php?.versions ?? [];
  const lifecycleAvailable = Boolean(php?.lifecycle_available);

  // The URL's version wins so a reload keeps your place; otherwise the default.
  const selected =
    versions.find((version) => version.version === sp?.version)?.version ??
    php?.default ??
    versions[0]?.version ??
    null;

  const current =
    versions.find((version) => version.version === selected) ?? null;

  const installState = versionState(current);
  const ordered = [...versions].sort((a, b) => compareVersions(b.version, a.version));

  // Install sits in the table's header, or in the empty state when there is no table.
  const install = (
    <InstallVersionButton
      runtime="php"
      installable={php?.installable ?? []}
      installed={versions}
      canManage={canManage}
      lifecycleAvailable={lifecycleAvailable}
    />
  );
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
        <AutoRefresh
          intervalMs={RUNTIME_POLL_MS}
          stopAfterMs={RUNTIME_POLL_STOP_MS}
        />
      ) : null}

      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* No PHP installed is a normal state, not an error. */}
      {versions.length === 0 ? (
        <EmptyState
          icon={FileCode2}
          title={t("empty.title")}
          description={t("empty.description")}
          // With no versions there is no table, so the empty state carries Install.
          action={install}
        />
      ) : (
        <>
          <VersionsCard
            namespace="php"
            gridClass={rowGrid("php", lifecycleAvailable)}
            columns={[
              t("table.colVersion"),
              ...(lifecycleAvailable ? [t("table.colSupport")] : []),
              t("table.colUsedBy"),
              t("table.colActions"),
            ]}
            action={install}
            footnote={t("versions.defaultHint")}
          >
            {/* Newest first: the API lists in-flight installs ahead of installed versions. */}
            {ordered.map((version) => (
              /* Keyed on version: each row holds its own confirm and running state. */
              <VersionSummary
                key={version.version}
                version={version}
                canManage={canManage}
                lifecycleAvailable={lifecycleAvailable}
                selected={version.version === selected}
                selectable
              />
            ))}
          </VersionsCard>

          {current ? (
            <section aria-labelledby="php-settings-title" className="space-y-3">
              <RevealSettings version={selected} targetId="php-settings-title" />
              <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                <div className="min-w-0 space-y-1">
                  {/* Clears the sticky top bar when scrolled to. */}
                  <h2 id="php-settings-title" className="scroll-mt-[calc(var(--app-chrome,4rem)+1rem)] text-[15px] font-semibold tracking-tight">
                    {t("settings.title", { version: selected })}
                  </h2>
                  <p className="text-sm text-muted-foreground">
                    {t("settings.description", { version: selected })}
                  </p>
                </div>
                {versions.length > 1 ? <SettingsVersionPicker versions={ordered} selected={selected} /> : null}
              </div>

              {/* In place of the settings, explain why they are unavailable. */}
              {installState ? (
                // Shared with the Node page so the two cannot drift.
                <RuntimeStatusNotice version={current} versionLabel={selected} namespace="php" />
              ) : (
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
                  // Keyed on version: its buffer belongs to one version's file.
                  ini={<IniEditor key={selected} version={selected} canManage={canManage} />}
                />
              )}
            </section>
          ) : null}
        </>
      )}
    </div>
  );
}
