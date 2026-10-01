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

/**
 * Command field with framework templates tucked into the label row. A template
 * fills BOTH command and expression — the API pairs them deliberately, since
 * WordPress cron wants a five-minute tick while the Laravel scheduler wants
 * every minute. Template commands carry a {path} placeholder that must be
 * resolved before submit, so picking one reveals the path input.
 */
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

  // Only sites with a `path` (absent on an older API, which would write
  // "undefined"). Provisioning sites are kept: the backend derives the path,
  // so it is correct before the directory exists.
  const sites = applications.filter((application) => application.path);
  const selectedSite = sites.find((application) => String(application.id) === source);

  function applyTemplate(tpl, dir) {
    // The placeholder stays visible until a path is typed, so it's obvious what
    // still needs filling — Zod blocks submitting it unresolved.
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
      // Reset `source` too: the site picker renders from it, and a stale site
      // would make the next template resolve {path} against nothing.
      setSource("");
      // Clearing it is an edit too: on an existing job this is how you take a
      // template off, and Save has to be reachable afterwards.
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

    // Fill "Run as" only while empty: the wrong user leaves root-owned files
    // that break deploys, but an existing choice is the user's.
    if (!form.getValues("run_as") && site.system_user?.id) {
      form.setValue("run_as", String(site.system_user.id), {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  }

  // A quick-start template writes itself into the form on mount. No
  // `shouldDirty`, unlike elsewhere: an untouched form must not trip the leave-guard.
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

          {/* Pick the site rather than type its directory (a typo fails
              silently in cron). "Enter a path myself" covers other directories. */}
          {sites.length > 0 ? (
            <Select value={source} onValueChange={onPickSite}>
              <FormControl>
                {/* Two-line value (name + path) needs four overrides at
                    SelectTrigger's specificity: the fixed `h-9`, `line-clamp-1`,
                    the value's `items-center`, and `flex!` because
                    `line-clamp-none` resets display to `block`. */}
                <SelectTrigger
                  // `text-left`: SelectTrigger is a <button>, which the UA
                  // stylesheet centres; visible at full width on the placeholder.
                  className="h-auto w-full py-2 text-left data-[size=default]:h-auto *:data-[slot=select-value]:line-clamp-none *:data-[slot=select-value]:flex! *:data-[slot=select-value]:w-full *:data-[slot=select-value]:min-w-0 *:data-[slot=select-value]:flex-col *:data-[slot=select-value]:items-stretch! *:data-[slot=select-value]:gap-0.5"
                >
                  <SelectValue placeholder={t("form.pathPickerPlaceholder")}>
                    {/* `items-stretch!` is important-flagged because tailwind-merge
                        does not dedupe two `*:data-[slot=…]` variants, so the base
                        `items-center` would win. */}
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

                {/* SelectGroup is required: Radix throws "`SelectLabel` must be
                    used within `SelectGroup`" at render time. */}
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
