"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Trash2, Loader2 } from "lucide-react";
import { LifecycleBadge } from "@/components/runtime/lifecycle-badge";
import { setDefaultPhpVersion, removePhpVersion } from "@/lib/api/php";
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
import { apiMessage } from "@/lib/api/error-message";

/**
 * What the selected version is, and everything you can do from here.
 *
 * One card rather than a card per action: the facts you need to decide
 * ("is anything using this?") and the buttons that act on them belong in the
 * same glance. `makeDefault` and `remove` appear only when they are genuinely
 * available — on a one-version server neither exists, because you cannot remove
 * the only version and it is already the default.
 *
 * `children` carries the php.ini button and `installButton` the install dialog;
 * both are owned by the page, which is a Server Component.
 */
export function VersionSummary({
  version,
  canManage,
  lifecycleAvailable = false,
  installButton,
  children,
}) {
  const t = useTranslations("php");
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);

  const usedBy = version.in_use_by ?? 0;
  const sites = version.sites ?? [];

  // The API omits `status` on older responses; absent means ready.
  const installState = version.status && version.status !== "ready" ? version.status : null;

  // Nothing is on disk, so anything that reads or writes this install fails.
  // Removing is the exception — clearing up a failed install is exactly what
  // you would want to do next.
  const notReadyReason = installState
    ? installState === "installing"
      ? t("versions.stillInstalling")
      : t("versions.installFailedShort")
    : null;

  const removeReason = !canManage
    ? t("noPermission")
    : version.in_use_by_panel
      ? t("versions.panelRuns")
      : version.is_default
        ? t("versions.isDefault")
        : usedBy > 0
          ? t("versions.usedBy", { count: usedBy })
          : null;

  async function makeDefault() {
    setPending(true);
    try {
      await setDefaultPhpVersion(version.version);
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
      await removePhpVersion(version.version);
      toast.success(t("versions.removed", { version: version.version }));
      setConfirming(false);
      router.refresh();
    } catch (error) {
      // The API names the sites in its message, which is more useful than
      // anything this page could compose.
      toast.error(apiMessage(error, t("versions.removeFailed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex flex-wrap items-center gap-2 text-base font-semibold">
          {t("versions.name", { version: version.version })}
          {/* Filled, not outlined: "Default" is a state this version is in, and
              it should not read like the outline tags used for plain labels. */}
          {version.is_default ? (
            <Badge variant="secondary" className="font-normal">
              {t("versions.default")}
            </Badge>
          ) : null}
          {/* Said out loud. A version whose install failed used to look exactly
              like a healthy one — same title, same "no sites use this yet". */}
          {installState === "failed" ? (
            <Badge variant="destructive" className="font-normal">
              {t("versions.statusFailed")}
            </Badge>
          ) : installState === "installing" ? (
            <Badge variant="warning" className="font-normal">
              <Loader2 className="size-3 animate-spin" />
              {t("versions.statusInstalling")}
            </Badge>
          ) : (
            <LifecycleBadge
              lifecycle={version.lifecycle}
              namespace="php"
              available={lifecycleAvailable}
            />
          )}
        </CardTitle>

        {/* Names, because the question behind "can I remove this?" is which
            sites go down if you do. */}
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
          {/* Only when the date is news. On a supported version the green badge
              already says what you need, and a 2028 date is trivia. */}
          {lifecycleAvailable &&
          version.lifecycle?.eol_date &&
          version.lifecycle.status !== "active"
            ? ` ${
                version.lifecycle.status === "eol"
                  ? t("lifecycle.endedOn", { date: version.lifecycle.eol_date })
                  : t("lifecycle.endsOn", { date: version.lifecycle.eol_date })
              }`
            : null}
        </CardDescription>

        {/* "Default" reads as "every site now uses this", which it isn't. Said
            plainly and only where the badge is, rather than left to be found
            out by changing it. */}
        {version.is_default ? (
          <p className="text-xs text-muted-foreground">{t("versions.defaultHint")}</p>
        ) : null}
      </CardHeader>

      {/* Same footer shape as every other card in the panel: installing sits on
          the left because it adds a version, the rest act on this one. */}
      <CardFooter className="flex-wrap justify-between gap-3 border-t bg-muted/30 py-4">
        {installButton}

        <div className="flex flex-wrap items-center gap-2">
          {children}

          {version.is_default ? null : (
            <ReasonTooltip reason={notReadyReason ?? (canManage ? null : t("noPermission"))}>
              <Button
                variant="outline"
                disabled={!canManage || pending || Boolean(notReadyReason)}
                onClick={makeDefault}
              >
                {t("versions.makeDefault")}
              </Button>
            </ReasonTooltip>
          )}

          {/* Hidden entirely on the panel's own version — the API refuses it, but
              a button that exists to be refused is still a trap. */}
          {version.in_use_by_panel ? null : (
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
          )}
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
