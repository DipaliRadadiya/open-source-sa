"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useUnsaved } from "@/components/ui/unsaved-guard";
import { Badge } from "@/components/ui/badge";

// Beside the settings heading, so switching version needs no trip back up the table
// when many are installed (Krishna, 12 Oct). Keeps the open tab; asks before dropping a php.ini edit.
export function SettingsVersionPicker({ versions, selected }) {
  const t = useTranslations("php");
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const { guardNavigation } = useUnsaved();

  function pick(version) {
    if (version === selected) return;
    const params = new URLSearchParams(searchParams);
    params.set("version", version);
    const href = `${pathname}?${params.toString()}`;
    if (!guardNavigation(href)) router.push(href, { scroll: false });
  }

  return (
    <Select value={selected} onValueChange={pick}>
      {/* A card surface and its own label: bare on the tinted page it read as text, not a control. */}
      <SelectTrigger
        className="h-9 w-auto min-w-44 gap-2 bg-card shadow-e1 dark:bg-card"
        aria-label={t("versions.pickerLabel")}
      >
        <span className="text-xs text-muted-foreground">{t("table.colVersion")}</span>
        <SelectValue />
      </SelectTrigger>
      <SelectContent align="end">
        {versions.map((version) => (
          <SelectItem key={version.version} value={version.version}>
            {t("versions.name", { version: version.version })}
            {version.is_default ? (
              <Badge variant="muted" className="font-normal">
                {t("versions.default")}
              </Badge>
            ) : null}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
