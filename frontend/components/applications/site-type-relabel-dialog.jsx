import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowRight, Check, HardDrive, Tags } from "lucide-react";
import { cn } from "@/lib/utils";
import { changeApplicationSiteType } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";

// `min-w-0` + `truncate` so a long title shortens instead of pushing the arrow off-centre.
function TypeSide({ name, title, emphasis = false }) {
  return (
    <span className="flex min-w-0 items-center gap-2">
      <SiteTypeLogo name={name} size="h-6 w-8" />
      <span
        className={cn(
          "truncate text-sm",
          emphasis ? "font-medium text-foreground" : "text-muted-foreground",
        )}
        title={title}
      >
        {title}
      </span>
    </span>
  );
}

/** One labelled answer, as a `<dl>` row so screen readers get the pairing. */
function Consequence({ icon: Icon, tone, label, children }) {
  return (
    <div className="flex items-start gap-2.5 p-3">
      <Icon
        className={cn(
          "mt-0.5 size-4 shrink-0",
          tone === "success" ? "text-success" : "text-warning",
        )}
        aria-hidden
      />
      <div className="min-w-0 space-y-0.5">
        <dt className="text-xs font-medium text-foreground">{label}</dt>
        <dd className="text-xs leading-snug text-muted-foreground">{children}</dd>
      </div>
    </div>
  );
}

// Relabelling changes what the panel offers, not what is on disk; the body states both.
export function SiteTypeRelabelDialog({
  open,
  onOpenChange,
  application,
  target,
  targetTitle,
  // The file the verdict rests on; checkable, unlike a confidence score.
  matched = null,
}) {
  const t = useTranslations("applications.siteTypeDetection");
  const { refreshAndWait } = useRefresh();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  function handleOpenChange(next) {
    // Cleared here: opening via `target` skips onOpenChange, so a stale error would survive.
    if (!next) setError(null);
    onOpenChange(next);
  }

  async function confirm() {
    setPending(true);
    setError(null);
    try {
      await changeApplicationSiteType(application.id, target);
      // Refresh, not a local patch: the type decides the site's screens, so the nav changes too.
      await refreshAndWait();
      toast.success(t("applied", { type: targetTitle }));
      onOpenChange(false);
    } catch (err) {
      // Backend refusals are worth reading, so kept in the dialog rather than a toast.
      setError(apiMessage(err, t("failed")));
    } finally {
      setPending(false);
    }
  }

  if (!target) return null;

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={Tags}
      title={t("confirmTitle", { type: targetTitle })}
      description={matched ? t("confirmFound", { file: matched }) : t("confirmNarrow")}
      confirmLabel={t("confirmAction")}
      pending={pending}
      error={error}
      onConfirm={confirm}
    >
      <div className="space-y-3">
        {/* Each mark with its own name: most marks do not spell their name. */}
        <div className="flex items-center justify-center gap-3 rounded-lg border bg-muted/30 p-3">
          <TypeSide
            name={application.site_type}
            title={application.site_type_title ?? application.site_type}
          />
          <ArrowRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          {/* The destination keeps foreground weight; the current type is muted. */}
          <TypeSide name={target} title={targetTitle} emphasis />
        </div>

        {/* Two labelled rows: what changes, and what does not. */}
        <dl className="divide-y rounded-lg border">
          <Consequence icon={Check} tone="success" label={t("changesLabel")}>
            {t("changesBody", { type: targetTitle })}
          </Consequence>
          <Consequence icon={HardDrive} tone="warning" label={t("unchangedLabel")}>
            {t("unchangedBody")}
          </Consequence>
        </dl>

        {/* Only when widening; narrowing back needs no evidence, so this promise holds. */}
        {matched ? (
          <p className="text-xs text-muted-foreground">
            {t("reversible", { from: application.site_type_title ?? application.site_type })}
          </p>
        ) : null}
      </div>
    </ConfirmDialog>
  );
}
