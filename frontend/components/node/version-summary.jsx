"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Loader2, Trash2 } from "lucide-react";
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
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

/**
 * The selected Node version and everything you can do from here.
 *
 * Same card as PHP's, minus the panel-version rule (the panel doesn't run on
 * Node) and plus npm, which belongs to the version rather than the machine.
 */
export function VersionSummary({
  version,
  canManage,
  lifecycleAvailable = false,
  installButton,
}) {
  const t = useTranslations("node");
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [npm, setNpm] = useState(version.npm_version ?? null);

  const usedBy = version.in_use_by ?? 0;
  const sites = version.sites ?? [];
  const removeReason = !canManage
    ? t("noPermission")
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
        <CardTitle className="flex flex-wrap items-center gap-2 text-base font-semibold">
          {t("versions.name", { version: version.version })}
          {version.is_default ? (
            <Badge variant="secondary" className="font-normal">
              {t("versions.default")}
            </Badge>
          ) : null}
          <LifecycleBadge
            lifecycle={version.lifecycle}
            namespace="node"
            available={lifecycleAvailable}
          />
        </CardTitle>

        <CardDescription>
          {sites.length > 0
            ? version.sites_truncated
              ? t("versions.usedByNamesMore", {
                  sites: sites.join(", "),
                  count: usedBy - sites.length,
                })
              : t("versions.usedByNames", { sites: sites.join(", ") })
            : usedBy > 0
              ? t("versions.usedByCount", { count: usedBy })
              : t("versions.usedByNone")}
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

        {version.is_default ? (
          <p className="text-xs text-muted-foreground">{t("versions.defaultHint")}</p>
        ) : null}
      </CardHeader>

      <CardFooter className="flex-wrap justify-between gap-3 border-t bg-muted/30 py-4">
        {installButton}

        <div className="flex flex-wrap items-center gap-2">
          {/* npm ships inside Node and is updated separately. Null means it
              couldn't be read — no number is better than a wrong one, so the
              control goes away rather than claiming to update nothing. */}
          {npm ? (
            <ReasonTooltip reason={canManage ? null : t("noPermission")}>
              <Button variant="outline" disabled={!canManage || pending} onClick={upgradeNpm}>
                {pending ? <Loader2 className="size-4 animate-spin" /> : null}
                {t("npm.action", { version: npm })}
              </Button>
            </ReasonTooltip>
          ) : null}

          {version.is_default ? null : (
            <ReasonTooltip reason={canManage ? null : t("noPermission")}>
              <Button variant="outline" disabled={!canManage || pending} onClick={makeDefault}>
                {t("versions.makeDefault")}
              </Button>
            </ReasonTooltip>
          )}

          <ReasonTooltip reason={removeReason}>
            <Button
              variant="ghost"
              className="text-destructive/80 hover:bg-destructive/10 hover:text-destructive"
              disabled={Boolean(removeReason) || pending}
              onClick={() => setConfirming(true)}
            >
              {t("versions.remove")}
            </Button>
          </ReasonTooltip>
        </div>
      </CardFooter>

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
