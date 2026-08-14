"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Trash2 } from "lucide-react";
import { RuntimeStatusBadge, versionState } from "@/components/runtime/version-status";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { setDefaultNodeVersion, removeNodeVersion, updateNodeNpm } from "@/lib/api/node";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Card,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

/**
 * The selected Node version and everything you can do from here.
 *
 * Same card as PHP's, minus the panel-version rule (the panel doesn't run on
 * Node) and plus npm, which belongs to the version rather than the machine.
 * Install lives beside the version picker, not here.
 */
export function VersionSummary({ version, canManage, lifecycleAvailable = false }) {
  const t = useTranslations("node");
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [npm, setNpm] = useState(version.npm_version ?? null);

  const usedBy = version.in_use_by ?? 0;
  const sites = version.sites ?? [];

  // This card had no notion of install state at all: a version mid-install
  // rendered exactly like a healthy one, with working buttons that acted on
  // something not yet on disk. PHP has handled these four states for a while;
  // now both read the same helper.
  const installState = versionState(version);

  // Nothing is on disk while it installs or purges, so anything that reads or
  // writes this version fails. Removing is not an exception for Node the way it
  // is for a failed PHP install: pressing Remove twice sent a second request
  // that answered 404 the moment the first finished.
  const notReadyReason =
    installState === "installing"
      ? t("versions.stillInstalling")
      : installState === "removing"
        ? t("versions.stillRemoving")
        : installState === "failed"
          ? t("versions.installFailedShort")
          : null;

  const removeReason = !canManage
    ? t("noPermission")
    : installState === "installing" || installState === "removing"
      ? notReadyReason
      : version.is_default
        ? t("versions.isDefault")
        : usedBy > 0
          ? t("versions.usedBy", { count: usedBy })
          : null;

  async function makeDefault() {
    setPending(true);
    try {
      await setDefaultNodeVersion(version.version);
      toast.success(t("versions.defaultSet", { version: version.version }));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("versions.defaultFailed")));
    } finally {
      setPending(false);
    }
  }

  async function remove() {
    setPending(true);
    try {
      await removeNodeVersion(version.version);
      toast.success(t("versions.removed", { version: version.version }));
      setConfirming(false);
      router.refresh();
    } catch (error) {
      // The API names every site pinning it, which is more useful than
      // anything this page could compose.
      toast.error(apiMessage(error, t("versions.removeFailed")));
    } finally {
      setPending(false);
    }
  }

  async function upgradeNpm() {
    setPending(true);
    try {
      // The response carries the new number, so the row updates without
      // re-fetching the whole page for one string.
      const { data } = await updateNodeNpm(version.version);
      if (data?.npm_version) setNpm(data.npm_version);
      toast.success(t("npm.updated", { version: data?.npm_version ?? "" }));
    } catch (error) {
      toast.error(apiMessage(error, t("npm.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        {/* Every action for this version on one line, with the version it acts
            on. A footer bar underneath repeated the card's own subject and
            split the controls across two places for no reason. */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <CardTitle className="flex flex-wrap items-center gap-2 text-base font-semibold">
            {t("versions.name", { version: version.version })}
            {version.is_default ? (
              <Badge variant="secondary" className="font-normal">
                {t("versions.default")}
              </Badge>
            ) : null}
            <RuntimeStatusBadge version={version} namespace="node" />
            <LifecycleBadge
              lifecycle={version.lifecycle}
              namespace="node"
              available={lifecycleAvailable}
            />
          </CardTitle>

          {/* Not shrink-0 — same fault the PHP card had. A shrink-0 flex item
              takes its max-content width, all three buttons on one line, and
              refuses to give any back, so its own flex-wrap never gets a chance
              to fire and Remove ends up under the card's edge. */}
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            {/* npm ships inside Node and is updated separately. Null means it
                couldn't be read — no number is better than a wrong one, so the
                control goes away rather than claiming to update nothing. */}
            {npm ? (
              <ReasonTooltip reason={canManage ? notReadyReason : t("noPermission")}>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || pending || Boolean(notReadyReason)}
                  onClick={upgradeNpm}
                >
                  {pending ? <Loader2 className="size-4 animate-spin" /> : null}
                  {t("npm.action", { version: npm })}
                </Button>
              </ReasonTooltip>
            ) : null}

            {version.is_default ? null : (
              <ReasonTooltip reason={canManage ? notReadyReason : t("noPermission")}>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={!canManage || pending || Boolean(notReadyReason)}
                  onClick={makeDefault}
                >
                  {t("versions.makeDefault")}
                </Button>
              </ReasonTooltip>
            )}

            <ReasonTooltip reason={removeReason}>
              <Button
                variant="ghost"
                size="sm"
                className="text-destructive/80 hover:bg-destructive/10 hover:text-destructive"
                disabled={Boolean(removeReason) || pending}
                onClick={() => setConfirming(true)}
              >
                {t("versions.remove")}
              </Button>
            </ReasonTooltip>
          </div>
        </div>

        {/* The count answers "can I remove this?"; the names answer "what
            breaks if I do?". See the PHP card — run together as one sentence
            they became a grey paragraph with the count buried at the end. */}
        <CardDescription>
          {usedBy > 0 ? t("versions.usedByCount", { count: usedBy }) : t("versions.usedByNone")}
          {/* Only when the date is news — on a supported line the green badge
              already says what you need. */}
          {lifecycleAvailable &&
          version.lifecycle?.eol_date &&
          version.lifecycle.status !== "current" &&
          version.lifecycle.status !== "lts"
            ? ` ${
                version.lifecycle.status === "eol"
                  ? t("lifecycle.endedOn", { date: version.lifecycle.eol_date })
                  : t("lifecycle.endsOn", { date: version.lifecycle.eol_date })
              }`
            : null}
        </CardDescription>

        {/* Tags, not prose: each name is one scannable unit. The API sends at
            most five and tells us how many it held back. */}
        {sites.length > 0 ? (
          <ul className="flex flex-wrap gap-1.5 pt-1">
            {sites.map((site) => (
              <li
                key={site}
                className="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground"
              >
                {site}
              </li>
            ))}
            {version.sites_truncated && usedBy > sites.length ? (
              <li className="px-1 py-0.5 text-xs text-muted-foreground">
                {t("versions.moreCount", { count: usedBy - sites.length })}
              </li>
            ) : null}
          </ul>
        ) : null}

        {version.is_default ? (
          <p className="text-xs text-muted-foreground">{t("versions.defaultHint")}</p>
        ) : null}
      </CardHeader>

      <ConfirmDialog
        open={confirming}
        onOpenChange={(open) => !pending && setConfirming(open)}
        icon={Trash2}
        tone="destructive"
        title={t("versions.confirmRemoveTitle", { version: version.version })}
        description={t("versions.confirmRemoveBody")}
        cancelLabel={t("versions.confirmCancel")}
        confirmLabel={t("versions.remove")}
        pending={pending}
        onConfirm={remove}
      />
    </Card>
  );
}
