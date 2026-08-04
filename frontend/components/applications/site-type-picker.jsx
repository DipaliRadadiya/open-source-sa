"use client";

import { useMemo } from "react";
import { useTranslations } from "next-intl";
import { Code2, LayoutTemplate, PackageOpen, TriangleAlert } from "lucide-react";
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from "@/components/ui/select";

function TypeIcon({ type }) {
  const Icon = type.method === "git" ? Code2 : type.has_installer ? PackageOpen : LayoutTemplate;
  return <Icon className="size-4" aria-hidden />;
}

export function SiteTypePicker({ types = [], value, onChange }) {
  const t = useTranslations("applications");
  const ordered = useMemo(
    () => [...types].sort((a, b) => Number(b.popular) - Number(a.popular) || a.title.localeCompare(b.title)),
    [types],
  );
  const selected = types.find((type) => type.name === value);
  const groups = useMemo(() => ordered.reduce((all, type) => {
    const category = type.category || t("chooseType");
    all[category] = [...(all[category] ?? []), type];
    return all;
  }, {}), [ordered, t]);

  return (
    <div className="space-y-2">
      <Select value={value ?? ""} onValueChange={onChange}>
        <SelectTrigger className="h-10 w-full" aria-label={t("chooseType")}>
          <SelectValue placeholder={t("chooseType")} />
        </SelectTrigger>
        <SelectContent className="max-h-72" position="popper">
          {Object.entries(groups).map(([category, group]) => <SelectGroup key={category}><SelectLabel>{category}</SelectLabel>{group.map((type) => <SelectItem key={type.name} value={type.name} disabled={!type.available}>{type.title}{!type.available ? ` — ${type.unavailable_reason ?? t("unavailable")}` : ""}</SelectItem>)}</SelectGroup>)}
        </SelectContent>
      </Select>
      {selected ? <div className="flex items-start gap-2 text-xs leading-5 text-muted-foreground"><span className="mt-0.5 text-primary"><TypeIcon type={selected} /></span><p>{selected.tagline ?? (selected.has_installer ? t("guided.softwareIncluded") : t("guided.bringYourOwnCode"))}</p></div> : <p className="text-xs leading-5 text-muted-foreground">{t("guided.typeHint")}</p>}
      {selected && !selected.available ? <p className="flex gap-1.5 text-xs leading-5 text-destructive"><TriangleAlert className="mt-0.5 size-3.5 shrink-0" />{selected.unavailable_reason ?? t("unavailable")}</p> : null}
    </div>
  );
}
