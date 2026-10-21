import { getTranslations } from "next-intl/server";
import { Hexagon } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getNode } from "@/lib/node/get-node";
import { VersionsCard } from "@/components/runtime/versions-table";
import { rowGrid } from "@/lib/runtime/version-rows";
import { compareVersions } from "@/lib/runtime/version-range";
import { InstallVersionButton } from "@/components/runtime/install-version-button";
import { VersionSummary } from "@/components/node/version-summary";
import { SystemNodeNote } from "@/components/node/system-node-note";
import { LoadFailed } from "@/components/data-table/load-failed";
import { EmptyState } from "@/components/data-table/empty-state";
import { AutoRefresh } from "@/components/ui/auto-refresh";
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

export default async function NodePage() {
  const [permissions, t, { data, failed, status, failure, message }] =
    await Promise.all([getPermissions(), getTranslations("node"), getNode()]);

  if (!can(permissions, "node", "view"))
    return <PermissionDenied title={t("title")} feature="node" />;
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

  // Newest first: the API lists in-flight installs ahead of installed versions.
  const ordered = [...versions].sort((a, b) => compareVersions(b.version, a.version));

  // Install sits in the table's header, or in the empty state when there is no table.
  const install = (
    <InstallVersionButton
      runtime="node"
      installable={node?.installable ?? []}
      catalogError={node?.installable_error ?? null}
      installed={versions}
      canManage={canManage}
      lifecycleAvailable={lifecycleAvailable}
    />
  );

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
                ? node?.installable_error
                  ? t("install.catalogUnreachable")
                  : t("empty.noneInstallable")
                : t("empty.description")
            }
            action={install}
          />
          <SystemNodeNote system={node?.system} versions={node?.versions} />
        </div>
      ) : (
        <div className="space-y-4">
          <VersionsCard
            namespace="node"
            gridClass={rowGrid("node", lifecycleAvailable)}
            columns={[
              t("table.colVersion"),
              ...(lifecycleAvailable ? [t("table.colSupport")] : []),
              t("table.colNpm"),
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
              />
            ))}
          </VersionsCard>

          <SystemNodeNote system={node?.system} versions={node?.versions} />
        </div>
      )}
    </div>
  );
}
