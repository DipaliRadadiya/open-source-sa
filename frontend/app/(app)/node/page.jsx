import { getTranslations } from "next-intl/server";
import { Hexagon } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getNode } from "@/lib/node/get-node";
import { VersionBar } from "@/components/runtime/version-bar";
import { InstallVersionButton } from "@/components/runtime/install-version-button";
import { VersionSummary } from "@/components/node/version-summary";
import { SystemNodeNote } from "@/components/node/system-node-note";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { RuntimeStatusNotice } from "@/components/runtime/version-status";
import {
  anyInFlight,
  RUNTIME_POLL_MS,
  RUNTIME_POLL_STOP_MS,
} from "@/lib/runtime/in-flight";
import { PageHeader } from "@/components/ui/page-header";
import { PermissionDenied } from "@/components/sections/permission-denied";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("node");
  return { title: t("title") };
}

export default async function NodePage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, { data, failed, status, failure, message }] =
    await Promise.all([getPermissions(), getTranslations("node"), getNode()]);

  if (!can(permissions, "node", "view"))
    return <PermissionDenied title={t("title")} />;
  const canManage = can(permissions, "node", "manage");

  // A 409 is the honest answer on a Docker box, which runs nothing on the host at
  // all — not a failure to report. The sidebar already stops offering this screen,
  // so this is a bookmark or a tab left open across a stack change.
  if (failed && status === 409) {
    return (
      <div className="space-y-6">
        <PageHeader title={t("title")} />
        <EmptyState
          icon={Hexagon}
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

  const node = data;
  const versions = node?.versions ?? [];
  const lifecycleAvailable = Boolean(node?.lifecycle_available);

  // The version in the URL wins so a reload keeps the selection; otherwise the default.
  const selected =
    versions.find((version) => version.version === sp?.version)?.version ??
    node?.default ??
    versions[0]?.version ??
    null;

  const current =
    versions.find((version) => version.version === selected) ?? null;

  // fnm installs take minutes and finish silently, so in-flight versions are polled.
  const inFlight = anyInFlight(versions);

  return (
    <div className="space-y-6">
      {inFlight ? (
        <AutoRefresh
          intervalMs={RUNTIME_POLL_MS}
          stopAfterMs={RUNTIME_POLL_STOP_MS}
        />
      ) : null}

      <PageHeader title={t("title")} subtitle={t("subtitle")} />

      {/* No managed Node is a normal state, not an error; the note covers a system Node. */}
      {versions.length === 0 ? (
        <div className="space-y-4">
          <EmptyState
            icon={Hexagon}
            title={t("empty.title")}
            // With nothing installable there is no install button; explain why instead.
            description={
              (node?.installable ?? []).length === 0
                ? t("empty.noneInstallable")
                : t("empty.description")
            }
            action={
              <InstallVersionButton
                runtime="node"
                installable={node?.installable ?? []}
                installed={versions}
                canManage={canManage}
                lifecycleAvailable={lifecycleAvailable}
              />
            }
          />
          <SystemNodeNote system={node?.system} versions={node?.versions} />
        </div>
      ) : (
        <div className="space-y-4">
          {/* Install sits at the end of the version chips. With a single version
              there are no chips, so it joins that version's card actions. */}
          {versions.length > 1 ? (
            <VersionBar
              versions={versions}
              selected={selected}
              namespace="node"
              lifecycleAvailable={lifecycleAvailable}
              action={
                <InstallVersionButton
                  runtime="node"
                  installable={node?.installable ?? []}
                  installed={versions}
                  canManage={canManage}
                  lifecycleAvailable={lifecycleAvailable}
                />
              }
            />
          ) : null}

          {current ? (
            // Keyed on the version: the card seeds state from it on mount only.
            <VersionSummary
              key={current.version}
              version={current}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            >
              {versions.length === 1 ? (
                <InstallVersionButton
                  runtime="node"
                  installable={node?.installable ?? []}
                  installed={versions}
                  canManage={canManage}
                  lifecycleAvailable={lifecycleAvailable}
                />
              ) : null}
            </VersionSummary>
          ) : null}

          {/* Status of this version when it is not simply ready. Shared with PHP. */}
          <RuntimeStatusNotice version={current} versionLabel={selected} namespace="node" />

          <SystemNodeNote system={node?.system} versions={node?.versions} />
        </div>
      )}
    </div>
  );
}
