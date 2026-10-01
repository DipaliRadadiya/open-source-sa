import { useMemo, useState } from "react";
import { DownloadCloud, ShieldAlert, TriangleAlert } from "lucide-react";
import { useTranslations } from "next-intl";
import { FIREWALL_RESOURCE_TYPE } from "@/lib/schemas/sync";
import { adoptionPlan, unmetDependencies } from "@/lib/server/sync-selection";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";

/**
 * The one screen in this feature that writes.
 *
 * The API has no per-item selection: it adopts everything found, of the ticked
 * types, that is not ignored. So the exact count and composition are stated
 * immediately above the button.
 */
export function AdoptDialog({ open, onOpenChange, items, ignoredKeys, typesPresent, pending, onConfirm }) {
  const t = useTranslations("sync");

  const adoptable = useMemo(
    () => typesPresent.filter((type) => type !== FIREWALL_RESOURCE_TYPE),
    [typesPresent],
  );

  /*
   * null until the reader ticks something, meaning "everything adoptable".
   * Not `useState(adoptable)`: the dialog mounts before its items arrive (they
   * load a page at a time), which would freeze an empty selection.
   */
  const [picked, setPicked] = useState(null);
  const selected = picked ?? adoptable;
  const [includeFirewall, setIncludeFirewall] = useState(false);

  /*
   * Closing discards the selection: within one scan run the component stays
   * mounted, so Cancel must not leave unticked types behind. Reset on close,
   * not open, so the checkboxes do not visibly repaint.
   */
  function handleOpenChange(next) {
    if (!next) {
      setPicked(null);
      setIncludeFirewall(false);
    }
    onOpenChange(next);
  }

  const hasFirewall = typesPresent.includes(FIREWALL_RESOURCE_TYPE);

  /*
   * The types this adopt will actually run, computed ONCE and used for both
   * the request and the summary. Firewall rules have no tick-box (they are
   * gated by the warning checkbox), so `selected` never contains them.
   */
  const adopting = useMemo(
    () => (includeFirewall ? [...selected, FIREWALL_RESOURCE_TYPE] : selected),
    [selected, includeFirewall],
  );

  const plan = useMemo(
    () => adoptionPlan({ items, ignoredKeys, selectedTypes: adopting, includeFirewall }),
    [items, ignoredKeys, adopting, includeFirewall],
  );

  // `adopting`, not `selected` — the same list the count and the request use.
  const unmet = useMemo(() => unmetDependencies(adopting), [adopting]);

  function toggleType(type, checked) {
    setPicked((current) => {
      // First tick: start from what is currently shown, not from nothing.
      const base = current ?? adoptable;
      return checked ? [...base, type] : base.filter((entry) => entry !== type);
    });
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={handleOpenChange}
      icon={DownloadCloud}
      tone="default"
      title={t("adopt.title")}
      description={t("adopt.description")}
      className="sm:max-w-lg"
      cancelLabel={t("common.cancel")}
      confirmLabel={t("adopt.confirm", { count: plan.total })}
      confirmDisabled={plan.total === 0}
      pending={pending}
      onConfirm={() =>
        onConfirm({
          only: adopting,
          includeFirewall,
        })
      }
    >
      <div className="space-y-4">
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">{t("adopt.typesLegend")}</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            {adoptable.map((type) => (
              <div key={type} className="flex items-center gap-2">
                <Checkbox
                  id={`adopt-type-${type}`}
                  checked={selected.includes(type)}
                  onCheckedChange={(checked) => toggleType(type, checked === true)}
                />
                <Label
                  htmlFor={`adopt-type-${type}`}
                  className="flex items-center gap-1.5 font-normal"
                >
                  {t(`types.${type}`)}
                  <span className="text-xs text-muted-foreground">
                    {plan.perType.get(type) ?? 0}
                  </span>
                </Label>
              </div>
            ))}
          </div>
        </fieldset>

        {/* Set apart and never ticked by default: adopting a rule set can lock
            someone out, which is why the backend has a separate flag for it. */}
        {hasFirewall ? (
          <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 p-3">
            <Checkbox
              id="adopt-firewall"
              checked={includeFirewall}
              onCheckedChange={(checked) => setIncludeFirewall(checked === true)}
            />
            <div className="space-y-1">
              <Label htmlFor="adopt-firewall" className="font-normal" hint={t("adopt.includeFirewallHint")}>
                {t("adopt.includeFirewall")}
              </Label>
              <p className="flex items-start gap-1.5 text-xs text-warning">
                <ShieldAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                {t("adopt.firewallWarning")}
              </p>
            </div>
          </div>
        ) : null}

        {/* Unticking a parent type makes every child fail with `requires_…`;
            name them so it is a visible choice. */}
        {unmet.length ? (
          <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 p-3 text-xs text-warning">
            <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            <p>
              {t("adopt.unmetDependency", {
                types: unmet.map((entry) => t(`types.${entry.type}`)).join(", "),
                requires: [...new Set(unmet.map((entry) => t(`types.${entry.requires}`)))].join(", "),
              })}
            </p>
          </div>
        ) : null}

        <div className="rounded-lg border bg-muted/40 p-3 text-sm">
          <p className="font-medium">
            {t("adopt.summary", { count: plan.total, types: plan.typeCount })}
          </p>
          {plan.ignoredCount ? (
            <p className="mt-1 text-muted-foreground">
              {t("adopt.summaryIgnored", { count: plan.ignoredCount })}
            </p>
          ) : null}
          <p className="mt-1 text-muted-foreground">{t("adopt.summaryIrreversible")}</p>
        </div>
      </div>
    </ConfirmDialog>
  );
}
