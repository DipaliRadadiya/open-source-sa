import { getTranslations } from "next-intl/server";
import { Hexagon, Wrench } from "lucide-react";
import { getPermissions } from "@/lib/permissions/get-permissions";
import { can } from "@/lib/permissions/can";
import { getNode } from "@/lib/node/get-node";
import { read } from "@/lib/api/read";
import { buildToolsResponseSchema } from "@/lib/schemas/node";
import { Note } from "@/components/ui/note";
import Link from "@/components/ui/app-link";

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
  const [permissions, t, { data, failed, status, failure, message }, buildTools] =
    await Promise.all([
      getPermissions(),
      getTranslations("node"),
      getNode(),
      read("/build-tools", buildToolsResponseSchema).catch(() => ({ failed: true })),
    ]);

  if (!can(permissions, "node", "view"))
    return <PermissionDenied title={t("title")} feature="node" />;
  const canManage = can(permissions, "node", "manage");

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

  const ordered = [...versions].sort((a, b) => compareVersions(b.version, a.version));

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

  const inFlight = anyInFlight(versions);

  return (
    <div className="space-y-6">
      {inFlight ? (
        <AutoRefresh
          intervalMs={RUNTIME_POLL_MS}
          stopAfterMs={RUNTIME_POLL_STOP_MS}
        />
      ) : null}

      <PageHeader title={t("title")} />

      {/* Some npm packages compile on install and fail in node-gyp without build tools. */}
      {!buildTools.failed && buildTools.data && !buildTools.data.build_tools.installed ? (
        <Note icon={Wrench}>
          {t.rich(
            buildTools.data.build_tools.install_status === "installing"
              ? "buildTools.installing"
              : buildTools.data.build_tools.install_status === "failed"
                ? "buildTools.failed"
                : "buildTools.missing",
            {
              link: (chunks) => (
                <Link href="/setup" prefetch={false} className="font-medium text-primary underline-offset-4 hover:underline">
                  {chunks}
                </Link>
              ),
            },
          )}
        </Note>
      ) : null}

      {versions.length === 0 ? (
        <div className="space-y-4">
          <EmptyState
            icon={Hexagon}
            title={t("empty.title")}
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
          >
            {ordered.map((version) => (
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
