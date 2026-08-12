"use client";

import { useTranslations } from "next-intl";
import { ChevronDown, Wand2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import {
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

// Horizon supervises its own queue workers, so having both is a same-site
// conflict the API 422s on — greying the incompatible preset out here means
// that error never has to round-trip.
const MUTUALLY_EXCLUSIVE_KIND = { queue: "horizon", horizon: "queue" };

function conflictingKind(presetKind, workers) {
  const other = MUTUALLY_EXCLUSIVE_KIND[presetKind];
  if (!other) return null;
  return workers.some((w) => w.kind === other) ? other : null;
}

/**
 * Command field with a "Use template" dropdown into the preset list. The API
 * only takes a single command string (no per-flag fields like queue connection
 * or backoff), so a preset's job is to hand over a full, editable command —
 * not to drive a guided form the backend has nothing to receive.
 */
export function WorkerCommandField({ form, presets, onPick, workers = [] }) {
  const t = useTranslations("applications.workers");

  return (
    <FormField
      control={form.control}
      name="command"
      render={({ field }) => (
        <FormItem>
          <div className="flex items-center justify-between gap-2">
            <FormLabel required>{t("form.command")}</FormLabel>
            {presets.length > 0 ? (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="-my-1 h-7 gap-1 px-2 text-xs font-medium text-primary hover:bg-primary/10 hover:text-primary"
                  >
                    <Wand2 className="size-3.5" />
                    {t("form.useTemplate")}
                    <ChevronDown className="size-3.5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  {presets.map((p) => {
                    const conflict = conflictingKind(p.kind, workers);
                    return (
                      <MenuItemHint
                        key={p.key}
                        hint={conflict ? t(`form.conflict.${conflict}`) : null}
                      >
                        <DropdownMenuItem disabled={!!conflict} onSelect={() => onPick(p)}>
                          {p.title}
                        </DropdownMenuItem>
                      </MenuItemHint>
                    );
                  })}
                </DropdownMenuContent>
              </DropdownMenu>
            ) : null}
          </div>
          <FormControl>
            <Textarea
              rows={2}
              className="font-mono"
              autoComplete="off"
              spellCheck={false}
              placeholder="php8.4 artisan queue:work --sleep=3 --tries=3"
              {...field}
            />
          </FormControl>
          <p className="text-xs text-muted-foreground">{t("form.commandHint")}</p>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}
