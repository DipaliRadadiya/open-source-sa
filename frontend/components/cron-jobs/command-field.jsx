import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, FolderPen, Wand2 } from "lucide-react";
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
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const CUSTOM = "custom";
const CUSTOM_PATH = "__custom_path__";

// A template fills BOTH command and expression (the API pairs them: WordPress wants 5 min, Laravel 1).
// Its {path} placeholder must be resolved before submit, so picking one reveals the path input.
export function CommandField({
  form,
  presets,
  placeholder = "{path}",
  starterKey,
  applications = [],
}) {
  const t = useTranslations("cronJobs");
  const starter = starterKey
    ? presets.find((p) => p.key === starterKey && p.command)
    : null;
  const [template, setTemplate] = useState(starter?.command ?? null);
  const [path, setPath] = useState("");
  // Which site the path came from, or CUSTOM_PATH; empty means nothing picked yet.
  const [source, setSource] = useState("");

  // Only sites with a `path` (absent on older APIs). Provisioning sites are kept: the path is derived.
  const sites = applications.filter((application) => application.path);
  const selectedSite = sites.find((application) => String(application.id) === source);

  function applyTemplate(tpl, dir) {
    // The placeholder stays until a path is typed; Zod blocks submitting it unresolved.
    const resolved = dir.trim()
      ? tpl.replaceAll(placeholder, dir.trim().replace(/\/+$/, ""))
      : tpl;
    // Every change here is a user edit to the command, so mark dirty.
    form.setValue("command", resolved, {
      shouldValidate: Boolean(dir.trim()),
      shouldDirty: true,
    });
  }

  function onPick(preset) {
    if (preset.key === CUSTOM || !preset.command) {
      setTemplate(null);
      setPath("");
      // Reset `source` too, or the next template resolves {path} against a stale site.
      setSource("");
      // Clearing is an edit too: it takes a template off an existing job, and Save must be reachable.
      form.setValue("command", "", { shouldValidate: false, shouldDirty: true });
      return;
    }
    setTemplate(preset.command);
    applyTemplate(preset.command, path);
    if (preset.expression) {
      form.setValue("expression", preset.expression, {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  }

  function onPath(value) {
    setPath(value);
    if (template) applyTemplate(template, value);
  }

  function onPickSite(value) {
    setSource(value);
    if (value === CUSTOM_PATH) {
      onPath("");
      return;
    }

    const site = sites.find((application) => String(application.id) === value);
    if (!site) return;

    onPath(site.path);

    // Fill "Run as" only while empty: root-owned files break deploys, but an existing choice stands.
    if (!form.getValues("run_as") && site.system_user?.id) {
      form.setValue("run_as", String(site.system_user.id), {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  }

  // A quick-start template fills the form on mount; no `shouldDirty`, so the leave-guard stays quiet.
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
              <FormLabel required>{t("form.command")}</FormLabel>
              {presets.length > 0 ? (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      // Brand-tinted: muted-foreground read as disabled.
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
                className="font-mono"
                autoComplete="off"
                spellCheck={false}
                placeholder="php /home/deploy/myapp/artisan schedule:run"
                {...field}
              />
            </FormControl>
            {/* A hint rather than validation: both checks are usually, not always, right. */}
            <p className="text-xs text-muted-foreground">
              {t.rich("form.commandHint", {
                code: (chunks) => <code className="font-mono text-foreground">{chunks}</code>,
              })}
            </p>
            <FormMessage />
          </FormItem>
        )}
      />

      {needsPath ? (
        <FormItem>
          <FormLabel required hint={t("form.pathHint")}>{t("form.path")}</FormLabel>

          {/* Pick the site rather than type its directory: a typo fails silently in cron. */}
          {sites.length > 0 ? (
            <Select value={source} onValueChange={onPickSite}>
              <FormControl>
                {/* Two-line value: overrides h-9, line-clamp-1 and items-center; `flex!` as line-clamp-none sets block. */}
                <SelectTrigger
                  // `text-left`: SelectTrigger is a <button>, which the UA stylesheet centres.
                  className="h-auto w-full py-2 text-left data-[size=default]:h-auto *:data-[slot=select-value]:line-clamp-none *:data-[slot=select-value]:flex! *:data-[slot=select-value]:w-full *:data-[slot=select-value]:min-w-0 *:data-[slot=select-value]:flex-col *:data-[slot=select-value]:items-stretch! *:data-[slot=select-value]:gap-0.5"
                >
                  <SelectValue placeholder={t("form.pathPickerPlaceholder")}>
                    {/* `items-stretch!`: tailwind-merge does not dedupe `*:data-[slot=…]` variants. */}
                    {selectedSite ? (
                      <>
                        <span className="w-full truncate text-left">{selectedSite.name}</span>
                        <span className="w-full truncate text-left font-mono text-xs text-muted-foreground">
                          {selectedSite.path}
                        </span>
                      </>
                    ) : source === CUSTOM_PATH ? (
                      t("form.pathCustom")
                    ) : null}
                  </SelectValue>
                </SelectTrigger>
              </FormControl>
              {/* Capped so the list visibly scrolls instead of filling the screen. */}
              <SelectContent position="popper" className="max-h-72">
                {/* First, so it is never below the fold. */}
                <SelectItem value={CUSTOM_PATH}>
                  <span className="flex items-center gap-2">
                    <FolderPen className="size-4 text-muted-foreground" />
                    {t("form.pathCustom")}
                  </span>
                </SelectItem>

                <SelectSeparator />

                {/* SelectGroup is required: Radix throws if `SelectLabel` is used outside it. */}
                <SelectGroup>
                  <SelectLabel>{t("form.pathSitesGroup")}</SelectLabel>

                  {sites.map((application) => (
                    <SelectItem key={application.id} value={String(application.id)}>
                      {/* Stacked: a fixed label column would widen the dropdown past the dialog. */}
                      <span className="flex min-w-0 flex-col items-start gap-0.5 py-0.5">
                        <span className="truncate" title={application.name}>{application.name}</span>
                        <span className="truncate font-mono text-xs text-muted-foreground">
                          {application.path}
                        </span>
                      </span>
                    </SelectItem>
                  ))}
                </SelectGroup>
              </SelectContent>
            </Select>
          ) : null}

          {/* Shown once "Enter a path myself" is chosen, or when there are no sites. */}
          {sites.length === 0 || source === CUSTOM_PATH ? (
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
          ) : null}
        </FormItem>
      ) : null}
    </div>
  );
}
