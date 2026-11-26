import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, FolderPen, Wand2 } from "lucide-react";
import { presetFits } from "@/lib/cron-jobs/preset-fit";
import { resolveTemplate } from "@/lib/cron-jobs/resolve-template";
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
import { HoverHint } from "@/components/ui/hover-hint";

const CUSTOM = "custom";
const PHP_EXAMPLE = "php /home/deploy/myapp/artisan schedule:run";
const GENERIC_EXAMPLE = "/usr/bin/curl -fsS https://example.com/cron";
const CUSTOM_PATH = "__custom_path__";

export function CommandField({
  form,
  presets,
  placeholder = "{path}",
  phpPlaceholder = "{php}",
  starterKey,
  applications = [],
}) {
  const t = useTranslations("cronJobs");
  const starter = starterKey
    ? presets.find((p) => p.key === starterKey && p.command)
    : null;
  const [template, setTemplate] = useState(starter?.command ?? null);
  const [templateKey, setTemplateKey] = useState(starter?.key ?? null);
  const [path, setPath] = useState("");
  const [source, setSource] = useState("");

  const sites = applications.filter(
    (application) => application.path && (!templateKey || presetFits(templateKey, application)),
  );
  const selectedSite = sites.find((application) => String(application.id) === source);

  function applyTemplate(tpl, dir, site = null) {
    const resolved = resolveTemplate(tpl, { dir, site, placeholder, phpPlaceholder });
    form.setValue("command", resolved, {
      shouldValidate: Boolean(dir.trim()),
      shouldDirty: true,
    });
  }

  function onPick(preset) {
    if (preset.key === CUSTOM || !preset.command) {
      setTemplate(null);
      setTemplateKey(null);
      setPath("");
      // Reset source too, or {path} resolves against the old site.
      setSource("");
      linkSite(null);
      form.setValue("command", "", { shouldValidate: false, shouldDirty: true });
      return;
    }
    setTemplate(preset.command);
    setTemplateKey(preset.key);
    const picked = applications.find((application) => String(application.id) === source);
    if (picked && !presetFits(preset.key, picked)) {
      setSource("");
      linkSite(null);
      setPath("");
      applyTemplate(preset.command, "");
    } else {
      applyTemplate(preset.command, path, picked ?? null);
    }
    if (preset.expression) {
      form.setValue("expression", preset.expression, {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  }

  function onPath(value, site = null) {
    setPath(value);
    if (template) applyTemplate(template, value, site);
  }

  function linkSite(id) {
    form.setValue("application_id", id, { shouldDirty: true });
  }

  function onPickSite(value) {
    setSource(value);
    if (value === CUSTOM_PATH) {
      linkSite(null);
      onPath("");
      return;
    }

    const site = sites.find((application) => String(application.id) === value);
    if (!site) return;

    linkSite(site.id);
    onPath(site.path, site);

    // Run as follows the site: another site's user can't open its files.
    if (site.system_user?.id) {
      form.setValue("run_as", String(site.system_user.id), {
        shouldValidate: true,
        shouldDirty: true,
      });
    }
  }

  useEffect(() => {
    if (!starter) return;
    form.setValue("command", resolveTemplate(starter.command, { placeholder, phpPlaceholder }), { shouldValidate: false });
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
              {presets.some((p) => p.command) ? (
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
                placeholder={presets.some((p) => p.command) ? PHP_EXAMPLE : GENERIC_EXAMPLE}
                {...field}
              />
            </FormControl>
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

          {sites.length > 0 ? (
            <Select value={source} onValueChange={onPickSite}>
              <FormControl>
                <SelectTrigger
                  className="h-auto w-full py-2 text-left data-[size=default]:h-auto *:data-[slot=select-value]:line-clamp-none *:data-[slot=select-value]:flex! *:data-[slot=select-value]:w-full *:data-[slot=select-value]:min-w-0 *:data-[slot=select-value]:flex-col *:data-[slot=select-value]:items-stretch! *:data-[slot=select-value]:gap-0.5"
                >
                  <SelectValue placeholder={t("form.pathPickerPlaceholder")}>
                    {/* items-stretch!: tailwind-merge doesn't dedupe *:data-[slot=…] variants. */}
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
              <SelectContent position="popper" className="max-h-72">
                <SelectItem value={CUSTOM_PATH}>
                  <span className="flex items-center gap-2">
                    <FolderPen className="size-4 text-muted-foreground" />
                    {t("form.pathCustom")}
                  </span>
                </SelectItem>

                <SelectSeparator />

                {/* Radix throws if SelectLabel is outside a SelectGroup. */}
                <SelectGroup>
                  <SelectLabel>{t("form.pathSitesGroup")}</SelectLabel>

                  {sites.map((application) => (
                    <SelectItem key={application.id} value={String(application.id)}>
                      <span className="flex min-w-0 flex-col items-start gap-0.5 py-0.5">
                        <HoverHint content={application.name}><span className="truncate">{application.name}</span></HoverHint>
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

          {selectedSite?.php_binary && template?.includes(phpPlaceholder) ? (
            <p className="text-xs text-muted-foreground">
              {t("form.phpFromSite", { version: selectedSite.php_version ?? "", binary: selectedSite.php_binary })}
            </p>
          ) : null}

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
