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
import { PhpCommonSettings } from "@/components/php/php-common-settings";
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

  if (!can(permissions, "php", "view"))
    return <PermissionDenied title={t("title")} feature="php" />;
  const canManage = can(permissions, "php", "manage");

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

  const selected =
    versions.find((version) => version.version === sp?.version)?.version ??
    php?.default ??
    versions[0]?.version ??
    null;

  const current =
    versions.find((version) => version.version === selected) ?? null;

  const installState = versionState(current);
  const ordered = [...versions].sort((a, b) => compareVersions(b.version, a.version));

  const install = (
    <InstallVersionButton
      runtime="php"
      installable={php?.installable ?? []}
      installed={versions}
      canManage={canManage}
      lifecycleAvailable={lifecycleAvailable}
    />
  );
  const [{ data: extensions }, { data: ioncube, failed: ionCubeFailed }] = installState
    ? [{ data: null }, { data: null, failed: false }]
    : await Promise.all([getPhpExtensions(selected), getIonCube(selected)]);

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

      <PageHeader title={t("title")} />

      {versions.length === 0 ? (
        <EmptyState
          icon={FileCode2}
          title={t("empty.title")}
          description={t("empty.description")}
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
          >
            {ordered.map((version) => (
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
                  <h2 id="php-settings-title" className="scroll-mt-[calc(var(--app-chrome,4rem)+1rem)] text-[15px] font-semibold tracking-tight">
                    {t("settings.title", { version: selected })}
                  </h2>
                  <p className="text-sm text-muted-foreground">
                    {t("settings.description", { version: selected })}
                  </p>
                </div>
                {versions.length > 1 ? <SettingsVersionPicker versions={ordered} selected={selected} /> : null}
              </div>

              {installState ? (
                <RuntimeStatusNotice version={current} versionLabel={selected} namespace="php" />
              ) : (
                <PhpVersionTabs
                  initial={sp?.tab}
                  settings={<PhpCommonSettings key={selected} version={selected} canManage={canManage} />}
                  extensionCount={extensions?.extensions?.filter((e) => !e.builtin).length}
                  ionCubeState={ioncube}
                  ionCubeFailed={ionCubeFailed}
                  extensions={
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
                    <IonCubeCard
                      version={selected}
                      ioncube={ioncube}
                      failed={ionCubeFailed}
                      canManage={canManage}
                    />
                  }
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
