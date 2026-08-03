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
import { FileCode2, Loader2, TriangleAlert } from "lucide-react";

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

  return (
    <div className="space-y-6">
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
          {/* Only when there is a choice to make. One version means nothing to
              switch, and a switch with one option reads as a required step. */}
          {versions.length > 1 ? (
            <VersionBar
              versions={versions}
              selected={selected}
              namespace="php"
              lifecycleAvailable={lifecycleAvailable}
            />
          ) : null}

          {current ? (
            <VersionSummary
              version={current}
              canManage={canManage}
              lifecycleAvailable={lifecycleAvailable}
              installButton={
                <InstallVersionButton
                  runtime="php"
                  installable={php?.installable ?? []}
                  canManage={canManage}
                  lifecycleAvailable={lifecycleAvailable}
                />
              }
            >
              <IniEditor
                version={selected}
                canManage={canManage}
                unavailableReason={
                  installState
                    ? installState === "installing"
                      ? t("versions.stillInstalling")
                      : t("versions.installFailedShort")
                    : null
                }
              />
            </VersionSummary>
          ) : null}

          {/* Where the extensions card would be. Rendering nothing read as a
              broken page; the reason is a fact the version list already knows. */}
          {installState ? (
            <EmptyState
              icon={installState === "installing" ? Loader2 : TriangleAlert}
              title={
                installState === "installing"
                  ? t("versions.installingTitle", { version: selected })
                  : t("versions.installFailedTitle", { version: selected })
              }
              description={
                installState === "installing"
                  ? // "Started 17 minutes ago" is what tells you whether it is
                    // progressing or wedged; "a few minutes" never does.
                    current?.started_at_human
                    ? t("versions.installingSince", { when: current.started_at_human })
                    : t("versions.installingBody")
                  : // The server's own explanation, when it has one. Ours is a
                    // guess about a failure we did not witness. The reference
                    // gets its own line — it is a string to copy, not prose.
                    <>
                      {current?.message || t("versions.installFailedBody")}
                      {current?.reference ? (
                        <span className="mt-1.5 block font-mono text-xs break-all">
                          {current.reference}
                        </span>
                      ) : null}
                    </>
              }
            />
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
