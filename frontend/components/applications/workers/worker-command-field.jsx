import { useTranslations } from "next-intl";
import { ChevronDown, Wand2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { conflictingKind } from "@/lib/applications/worker-kind";
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

// The kind-conflict rule lives in lib/applications/worker-kind.js, shared with
// the kind select so the two never disagree.

/**
 * Command field with a "Use template" dropdown. The API takes a single command
 * string, so a preset just fills in a full, editable command.
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
            <FormLabel required hint={t("form.commandHint")}>{t("form.command")}</FormLabel>
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
              // An example from this application's own presets, not a fixed Laravel command.
              placeholder={presets.find((p) => p.command)?.command ?? t("form.commandPlaceholder")}
              {...field}
            />
          </FormControl>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}
