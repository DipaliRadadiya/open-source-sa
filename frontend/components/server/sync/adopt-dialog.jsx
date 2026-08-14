"use client";

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
 * It carries more than a yes/no because the API's scope model is unusual and
 * invisible from the list: there is no per-item selection, so what gets adopted
 * is "everything found, of the types ticked here, that you have not ignored".
 * Every product this pattern was surveyed against is opt-in with a checkbox per
 * row; ours is opt-out, and the only honest mitigation is to state the exact
 * count and its composition immediately above the button.
 */
export function AdoptDialog({ open, onOpenChange, items, ignoredKeys, typesPresent, pending, onConfirm }) {
  const t = useTranslations("sync");

  const adoptable = useMemo(
    () => typesPresent.filter((type) => type !== FIREWALL_RESOURCE_TYPE),
    [typesPresent],
  );

  const [selected, setSelected] = useState(adoptable);
  const [includeFirewall, setIncludeFirewall] = useState(false);

  const hasFirewall = typesPresent.includes(FIREWALL_RESOURCE_TYPE);

  const plan = useMemo(
    () => adoptionPlan({ items, ignoredKeys, selectedTypes: selected, includeFirewall }),
    [items, ignoredKeys, selected, includeFirewall],
  );

  const unmet = useMemo(() => unmetDependencies(selected), [selected]);

  function toggleType(type, checked) {
    setSelected((current) =>
      checked ? [...current, type] : current.filter((entry) => entry !== type),
    );
  }

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
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
          only: includeFirewall ? [...selected, FIREWALL_RESOURCE_TYPE] : selected,
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

        {/* Set apart deliberately, and never ticked by default: adopting a rule
            set is the one step here that can lock someone out of their own
            box, which is why the backend put it behind its own flag instead of
            leaving it in `only` with the rest. */}
        {hasFirewall ? (
          <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/5 p-3">
            <Checkbox
              id="adopt-firewall"
              checked={includeFirewall}
              onCheckedChange={(checked) => setIncludeFirewall(checked === true)}
            />
            <div className="space-y-1">
              <Label htmlFor="adopt-firewall" className="font-normal">
                {t("adopt.includeFirewall")}
              </Label>
              <p className="flex items-start gap-1.5 text-xs text-warning">
                <ShieldAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                {t("adopt.firewallWarning")}
              </p>
            </div>
          </div>
        ) : null}

        {/* Unticking a parent type does not narrow the run, it makes every
            child fail with `requires_…`. Saying which ones, by name, is the
            difference between a choice and twelve silent skips. */}
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
