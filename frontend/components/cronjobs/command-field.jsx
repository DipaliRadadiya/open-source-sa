"use client";

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
  // Which site the path came from, or CUSTOM_PATH once someone chooses to type
  // one. Empty means nothing picked yet, which is what the placeholder says.
  const [source, setSource] = useState("");

  // Only sites we can answer with. `path` is absent on an older API, and
  // offering those rows would write "undefined" into the command.
  //
  // Sites that are still provisioning are deliberately kept: the backend
  // derives the path rather than reading the disk, so it is the right answer
  // before the directory exists — and setting a job up while a site builds is
  // a reasonable thing to want.
  const sites = applications.filter((application) => application.path);
  const selectedSite = sites.find((application) => String(application.id) === source);

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
      // `source` too. It is the only thing the site picker renders from, so
      // leaving it behind kept a site's name on screen after its path had been
      // cleared — then the next template resolved {path} against nothing and
      // Create refused it for a missing directory that was visibly selected.
      setSource("");
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

  function onPickSite(value) {
    setSource(value);
    if (value === CUSTOM_PATH) {
      onPath("");
      return;
    }

    const site = sites.find((application) => String(application.id) === value);
    if (!site) return;

    onPath(site.path);

    // Fill "Run as" only while it is still empty. A cron job run as the wrong
    // user writes root-owned files into the site and the next deploy fails on
    // them — but a choice already made is the user's, not ours to overwrite.
    if (!form.getValues("run_as") && site.system_user?.id) {
      form.setValue("run_as", String(site.system_user.id), { shouldValidate: true });
    }
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
              <FormLabel required>{t("form.command")}</FormLabel>
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
                className="font-mono"
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
          <FormLabel required>{t("form.path")}</FormLabel>

          {/* Pick the site, not its directory. Everyone typing this by hand was
              copying a path out of the sites list anyway, and a typo here fails
              silently — cron runs, the file is not there, nothing says so.
              Same shape as "Run as" above: a list, plus an escape hatch for the
              directories that are not a panel-managed site. */}
          {sites.length > 0 ? (
            <Select value={source} onValueChange={onPickSite}>
              <FormControl>
                {/* The field has to answer "which folder" on its own — making
                    someone read the command box above to check the path is the
                    lookup this picker exists to remove.

                    Two lines therefore need four overrides, all at the
                    specificity SelectTrigger uses or they lose: the fixed
                    `h-9`, the `line-clamp-1` that crops the value to one line,
                    the value's `items-center` row direction — and `flex!`,
                    because `line-clamp-1` works by setting `display:-webkit-box`
                    and `line-clamp-none` resets that to `block`, which drops
                    the flex column and ran both lines together. */}
                <SelectTrigger
                  // `text-left` is not cosmetic: SelectTrigger is a <button>,
                  // and the UA stylesheet centres button text. Every other
                  // trigger hides that because its value is a content-sized
                  // flex row packed to the start — stretching this one to full
                  // width is what let the inherited centring show, on the
                  // placeholder most visibly.
                  className="h-auto w-full py-2 text-left data-[size=default]:h-auto *:data-[slot=select-value]:line-clamp-none *:data-[slot=select-value]:flex! *:data-[slot=select-value]:w-full *:data-[slot=select-value]:min-w-0 *:data-[slot=select-value]:flex-col *:data-[slot=select-value]:items-stretch! *:data-[slot=select-value]:gap-0.5"
                >
                  <SelectValue placeholder={t("form.pathPickerPlaceholder")}>
                    {/* `items-stretch!` above is important-flagged for a
                        reason: tailwind-merge does not dedupe two
                        `*:data-[slot=…]` variants of the same group, so the
                        base `items-center` survived alongside it and won on
                        source order. Setting `w-full` per line fixed the two
                        lines but NOT the placeholder — Radix renders that
                        itself, out of reach of any class put on a child. */}
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
              {/* Capped deliberately. Left to the available viewport height,
                  the list grew until it exactly filled the screen and then
                  spilled — putting "Enter a path myself" just below the fold
                  with nothing to suggest there was more. A shorter box that
                  visibly scrolls is honest at any number of sites. */}
              <SelectContent position="popper" className="max-h-72">
                {/* First, not last. At the bottom of a scrolling list it was
                    below the fold on any server with a handful of sites, so
                    the one option that says "you are not limited to these"
                    was the one nobody would find. The icon and the rule under
                    it mark it as a different kind of answer, not a site. */}
                <SelectItem value={CUSTOM_PATH}>
                  <span className="flex items-center gap-2">
                    <FolderPen className="size-4 text-muted-foreground" />
                    {t("form.pathCustom")}
                  </span>
                </SelectItem>

                <SelectSeparator />

                {/* SelectGroup is required, not decoration: Radix throws
                    "`SelectLabel` must be used within `SelectGroup`" at render
                    time. The build and lint both passed on the crash. */}
                <SelectGroup>
                  <SelectLabel>{t("form.pathSitesGroup")}</SelectLabel>

                  {sites.map((application) => (
                    <SelectItem key={application.id} value={String(application.id)}>
                      {/* Stacked, not the schedule's two columns: a cron
                          expression is 11 characters and a path is 45, so a
                          fixed label column would push the dropdown wider than
                          the dialog holding it. */}
                      <span className="flex min-w-0 flex-col items-start gap-0.5 py-0.5">
                        <span className="truncate">{application.name}</span>
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

          {/* Shown once "Enter a path myself" is chosen — and always when there
              are no sites to offer, so the field is never a dead end. */}
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

          <p className="text-xs text-muted-foreground">{t("form.pathHint")}</p>
        </FormItem>
      ) : null}
    </div>
  );
}
