"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, Wand2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

const CUSTOM = "custom";

/**
 * Command field with framework templates tucked into the label row. A template
 * fills BOTH command and expression — the API pairs them deliberately, since
 * WordPress cron wants a five-minute tick while the Laravel scheduler wants
 * every minute. Template commands carry a {path} placeholder that must be
 * resolved before submit, so picking one reveals the path input.
 */
export function CommandField({ form, presets, placeholder = "{path}", starterKey }) {
  const t = useTranslations("cronJobs");
  const starter = starterKey
    ? presets.find((p) => p.key === starterKey && p.command)
    : null;
  const [template, setTemplate] = useState(starter?.command ?? null);
  const [path, setPath] = useState("");

  function applyTemplate(tpl, dir) {
    // The placeholder stays visible until a path is typed, so it's obvious what
    // still needs filling — Zod blocks submitting it unresolved.
    const resolved = dir.trim()
      ? tpl.replaceAll(placeholder, dir.trim().replace(/\/+$/, ""))
      : tpl;
    form.setValue("command", resolved, { shouldValidate: Boolean(dir.trim()) });
  }

  function onPick(preset) {
    if (preset.key === CUSTOM || !preset.command) {
      setTemplate(null);
      setPath("");
      form.setValue("command", "", { shouldValidate: false });
      return;
    }
    setTemplate(preset.command);
    applyTemplate(preset.command, path);
    if (preset.expression) {
      form.setValue("expression", preset.expression, { shouldValidate: true });
    }
  }

  function onPath(value) {
    setPath(value);
    if (template) applyTemplate(template, value);
  }

  // A quick-start template has to write itself into the form; seeding local
  // state alone left the command box empty while the path field appeared.
  useEffect(() => {
    if (!starter) return;
    form.setValue("command", starter.command, { shouldValidate: false });
    if (starter.expression) {
      form.setValue("expression", starter.expression, { shouldValidate: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [starter?.key]);

  const needsPath = Boolean(template?.includes(placeholder));

  return (
    <div className="space-y-4">
      <FormField
        control={form.control}
        name="command"
        render={({ field }) => (
          <FormItem>
            <div className="flex items-center justify-between gap-2">
              <FormLabel>{t("form.command")}</FormLabel>
              {presets.length > 0 ? (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      // Brand-tinted: this is an offer of help, not chrome —
                      // muted-foreground read as disabled next to the label.
                      className="-my-1 h-7 gap-1 px-2 text-xs font-medium text-primary hover:bg-primary/10 hover:text-primary"
                    >
                      <Wand2 className="size-3.5" />
                      {t("form.useTemplate")}
                      <ChevronDown className="size-3.5" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="w-52">
                    {presets.map((p) => (
                      <DropdownMenuItem key={p.key} onSelect={() => onPick(p)}>
                        {p.label}
                      </DropdownMenuItem>
                    ))}
                  </DropdownMenuContent>
                </DropdownMenu>
              ) : null}
            </div>
            <FormControl>
              <Textarea
                rows={2}
                className="font-mono text-xs"
                autoComplete="off"
                spellCheck={false}
                placeholder="php /home/deploy/myapp/artisan schedule:run"
                {...field}
              />
            </FormControl>
            {/* The two things that break most first cron jobs. Shown as a hint
                rather than validation — both are usually right, not always. */}
            <ul className="space-y-0.5 text-xs text-muted-foreground">
              <li>{t("form.commandHintPath")}</li>
              <li>{t("form.commandHintLog")}</li>
            </ul>
            <FormMessage />
          </FormItem>
        )}
      />

      {needsPath ? (
        <FormItem>
          <FormLabel>{t("form.path")}</FormLabel>
          <FormControl>
            <Input
              className="font-mono"
              autoComplete="off"
              spellCheck={false}
              placeholder="/home/deploy/myapp"
              value={path}
              onChange={(e) => onPath(e.target.value)}
            />
          </FormControl>
          <p className="text-xs text-muted-foreground">{t("form.pathHint")}</p>
        </FormItem>
      ) : null}
    </div>
  );
}
