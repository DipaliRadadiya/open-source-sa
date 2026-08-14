import { redirect } from "next/navigation";
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
import { anyInFlight, RUNTIME_POLL_MS, RUNTIME_POLL_STOP_MS } from "@/lib/runtime/in-flight";

export const dynamic = "force-dynamic";

export async function generateMetadata() {
  const t = await getTranslations("node");
  return { title: t("title") };
}

export default async function NodePage({ searchParams }) {
  const sp = await searchParams;
  const [permissions, t, { data, failed }] = await Promise.all([
    getPermissions(),
    getTranslations("node"),
    getNode(),
  ]);

  if (!can(permissions, "node", "view")) redirect("/dashboard");
  const canManage = can(permissions, "node", "manage");

  if (failed || !data) return <LoadFailed description={t("loadFailed")} />;

  const node = data;
  const versions = node?.versions ?? [];
  const lifecycleAvailable = Boolean(node?.lifecycle_available);

  // The version in the URL wins so a reload keeps your place; otherwise the
  // default, which is the one most people came to look at.
  const selected =
    versions.find((version) => version.version === sp?.version)?.version ??
    node?.default ??
    versions[0]?.version ??
    null;

  const current = versions.find((version) => version.version === selected) ?? null;

  // Same reason as PHP: fnm takes minutes and finishes silently, so a page
  // rendered once sits on "Installing" until you navigate away and back.
  const inFlight = anyInFlight(versions);

  return (
    <div className="space-y-6">
      {inFlight ? (
        <AutoRefresh intervalMs={RUNTIME_POLL_MS} stopAfterMs={RUNTIME_POLL_STOP_MS} />
      ) : null}

      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
      </div>

      {/* No managed Node is a normal state on a fresh server, not an error —
          and the box may still have one of its own, which the note explains. */}
      {versions.length === 0 ? (
        <div className="max-w-5xl space-y-4">
          <EmptyState
            icon={Hexagon}
            title={t("empty.title")}
            // With nothing installable there is no button to offer, so inviting
            // someone to install would be a dead end. Say why instead.
            description={
              (node?.installable ?? []).length === 0
                ? t("empty.noneInstallable")
                : t("empty.description")
            }
            action={
              <InstallVersionButton
                runtime="node"
                installable={node?.installable ?? []}
                canManage={canManage}
                lifecycleAvailable={lifecycleAvailable}
              />
            }
          />
          <SystemNodeNote system={node?.system} />
        </div>
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
              namespace="node"
              lifecycleAvailable={lifecycleAvailable}
              action={
                <InstallVersionButton
                  runtime="node"
                  installable={node?.installable ?? []}
                  canManage={canManage}
                  lifecycleAvailable={lifecycleAvailable}
                />
              }
            />
          ) : (
            <InstallVersionButton
              runtime="node"
              installable={node?.installable ?? []}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            />
          )}

          {current ? (
            <VersionSummary
              version={current}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
            />
          ) : null}

          {/* What is happening to this version, when it is not simply ready.
              Node had nothing here at all — an install in flight looked like a
              finished one. Shared with PHP so the two cannot drift. */}
          <RuntimeStatusNotice version={current} versionLabel={selected} namespace="node" />

          <SystemNodeNote system={node?.system} />
        </div>
      )}
    </div>
  );
}
