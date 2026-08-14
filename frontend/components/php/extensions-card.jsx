"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ChevronDown, Loader2, SearchX, TriangleAlert } from "lucide-react";
import { setPhpExtension } from "@/lib/api/php";
import { Input } from "@/components/ui/input";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { Switch } from "@/components/ui/switch";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { apiMessage } from "@/lib/api/error-message";

/**
 * Which SAPIs disagree, if any.
 *
 * `sapis` is read-only and only ever drifts when someone ran `phpenmod` or
 * `phpdismod` by hand — the panel's toggle always writes every SAPI. Returns a
 * translation key, or null when everything agrees (the normal case, which says
 * nothing at all).
 */
function driftOf(extension) {
  const sapis = Object.entries(extension.sapis ?? {});
  if (sapis.length < 2) return null;
  const on = sapis.filter(([, value]) => value).map(([name]) => name);
  if (on.length === 0 || on.length === sapis.length) return null;

  if (on.includes("fpm") && !on.includes("cli")) return "driftWebOnly";
  if (on.includes("cli") && !on.includes("fpm")) return "driftCliOnly";
  return "driftPartial";
}

/**
 * One switch per extension.
 *
 * A row is a PACKAGE, not a module: `php8.4-mysql` provides mysqli, mysqlnd and
 * pdo_mysql, and three switches that must always move together would be a trap
 * rather than a choice. Turning one on installs it if it isn't there — an
 * install control plus a separate enable control is the same trap one level up.
 */
export function ExtensionsCard({ version, extensions, panelRequired = [], canManage }) {
  const t = useTranslations("php");
  const router = useRouter();
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState("all");
  const [pending, setPending] = useState(null);

  // Two lists, not one. A real server reports 96 extensions of which 16 are
  // compiled into PHP and can never be switched — and they sorted alphabetically
  // through the rest, so the first two pages were rows nobody can act on. They
  // are also the only rows with no description, which is what made the list
  // lurch between tall and short rows.
  const builtins = extensions.filter((extension) => extension.builtin);
  const changeable = extensions.filter((extension) => !extension.builtin);

  // Counted over what you can actually change. "29 of 96" included the 16 that
  // are on because they cannot be off.
  const onCount = changeable.filter((extension) => extension.enabled).length;

  const term = query.trim().toLowerCase();
  const matched = changeable
    .filter((extension) =>
      filter === "all" ? true : filter === "on" ? extension.enabled : !extension.enabled,
    )
    .filter((extension) => {
      if (!term) return true;
      if (extension.name.toLowerCase().includes(term)) return true;
      if (extension.modules.some((module) => module.toLowerCase().includes(term))) return true;
      // Searching the description is the point of having one: you know you want
      // to resize images, not that the package is called `imagick`.
      const key = `extensionInfo.${extension.name}`;
      return t.has(key) && t(key).toLowerCase().includes(term);
    });

  // No pager. Seven pages of a list that already has a search box and an
  // on/off filter is paging you have to navigate to use a filter you can see —
  // and it hid the answer to "is mysql on?" behind page 4. The list scrolls
  // inside a fixed height instead, so the card stays the same size whether it
  // holds three matches or eighty.
  const shown = matched;

  // Did the search only miss because the answer is compiled into PHP?
  const builtinMatch = term
    ? builtins.find((extension) => extension.name.toLowerCase().includes(term))
    : null;

  async function toggle(extension) {
    const next = !extension.enabled;
    setPending(extension.name);
    try {
      const response = await setPhpExtension(version, extension.name, next);
      // 202 means apt is queued — minutes, not milliseconds — so the message
      // says so rather than implying it is already done.
      toast.success(
        response.status === 202
          ? t("extensions.installing", { name: extension.name })
          : next
            ? t("extensions.enabled", { name: extension.name })
            : t("extensions.disabled", { name: extension.name }),
      );
      router.refresh();
    } catch (error) {
      // The reference id stays: it's what the backend needs to find this failure
      // in its own logs, and a 500 here is exactly when someone will ask.
      const reference = error.response?.data?.reference;
      toast.error([apiMessage(error, t("extensions.failed")), reference].filter(Boolean).join(" · "));
    } finally {
      setPending(null);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold">{t("extensions.title")}</CardTitle>
        <CardDescription>
          {t("extensions.summary", { on: onCount, total: changeable.length })}
        </CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <Input
            value={query}
            onChange={(event) => {
              setQuery(event.target.value);
            }}
            placeholder={t("extensions.search")}
            className="sm:max-w-64"
            autoComplete="off"
            spellCheck={false}
          />
          <ToggleGroup
            type="single"
            value={filter}
            onValueChange={(next) => {
              if (!next) return;
              setFilter(next);
            }}
            variant="outline"
            // flex-wrap: with `gap-1` these are three separate bordered buttons,
            // not a joined segmented bar, so they can drop to a second line
            // rather than run past the card. "Todas / Activadas / Desactivadas"
            // is wider than the English on a narrow card.
            className="flex-wrap gap-1"
          >
            <ToggleGroupItem value="all" className="px-3">
              {t("extensions.filterAll")}
            </ToggleGroupItem>
            <ToggleGroupItem value="on" className="px-3">
              {t("extensions.filterOn")}
            </ToggleGroupItem>
            <ToggleGroupItem value="off" className="px-3">
              {t("extensions.filterOff")}
            </ToggleGroupItem>
          </ToggleGroup>
        </div>

        {shown.length === 0 ? (
          <p className="flex items-center justify-center gap-2 py-8 text-center text-sm text-muted-foreground">
            <SearchX className="size-4 shrink-0" />
            {/* Searching for a built-in used to answer "no extensions match",
                which reads as "it is not here" about something PHP always has.
                Name it instead. */}
            {builtinMatch
              ? t("extensions.noMatchesBuiltin", { name: builtinMatch.name })
              : t("extensions.noMatches")}
          </p>
        ) : (
          <div className="relative">
            <div className="max-h-[32rem] overflow-y-auto rounded-lg border">
            <Table>
              <TableHeader>
                {/* The right column holds a switch on one row and a badge on the
                    next; unlabelled, they read as unrelated things. */}
                <TableRow className="sticky top-0 z-10 bg-muted hover:bg-muted">
                  <TableHead>{t("extensions.colName")}</TableHead>
                  {/* Narrow on a phone: at 390px a fixed 14rem column pushed the
                      switches off the screen entirely, so the one control on the
                      row couldn't be seen or touched. */}
                  <TableHead className="w-24 text-right sm:w-56">
                    {t("extensions.colStatus")}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {shown.map((extension) => {
                  const required = panelRequired.includes(extension.name);
                  const reason = !canManage
                    ? t("noPermission")
                    : required
                      ? t("extensions.panelNeeds")
                      : null;

                  return (
                    <TableRow key={extension.name}>
                      {/* min-h keeps every row the same height. Not every
                          extension has a description, and a list that jumps
                          between 47px and 61px rows cannot be scanned. */}
                      <TableCell className="max-w-0">
                        <span className="flex min-h-9 flex-col justify-center">
                        <span className="font-mono text-sm font-medium">{extension.name}</span>

                        {/* What it's FOR, in plain words. Only the extensions we
                            have real copy for get a line; a generic filler would
                            be worse than none. Truncated rather than wrapped so a
                            long description can't widen the table past the screen
                            and take the switch with it. */}
                        {t.has(`extensionInfo.${extension.name}`) ? (
                          <span className="block truncate text-xs text-muted-foreground">
                            {t(`extensionInfo.${extension.name}`)}
                          </span>
                        ) : null}

                        {/* apt's last words, while this extension is installing
                            and after it has failed. A toast said "installing"
                            and then vanished; when the install failed minutes
                            later there was nothing on the row to say why, and
                            "could not get lock" and "unable to locate package"
                            need different answers. */}
                        {extension.status === "installing" || extension.status === "failed" ? (
                          <span className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                            {extension.status === "installing" ? (
                              <Loader2 className="size-3 shrink-0 animate-spin" />
                            ) : null}
                            <span className="truncate font-mono">
                              {extension.output?.trimEnd().split("\n").pop() ||
                                (extension.current_step
                                  ? t(`versions.steps.${extension.current_step}`)
                                  : t("extensions.installingShort"))}
                            </span>
                          </span>
                        ) : null}
                        </span>
                      </TableCell>

                      <TableCell className="text-right">
                        {/* Compiled into PHP: nothing to switch and the API
                            refuses. Listed anyway so searching for it finds it.
                            The old "Built in" tag beside the name said the same
                            thing twice — this column is the answer. */}
                        {/* One shape for every row in this list: a switch, on
                            the same spot, every time. A panel-required row used
                            to swap the switch for an amber pill, so the right
                            edge of the table alternated between three unrelated
                            shapes and could not be scanned. It is still locked —
                            disabled, with the reason on hover — but it now reads
                            as "this one is fixed on", not as a different kind of
                            thing. */}
                        <ReasonTooltip reason={reason}>
                          <Switch
                            checked={extension.enabled}
                            onCheckedChange={() => toggle(extension)}
                            disabled={Boolean(reason) || pending === extension.name}
                            aria-label={t("extensions.toggle", { name: extension.name })}
                          />
                        </ReasonTooltip>

                        {/* Sits with the switch because it explains the switch:
                            `enabled` is all-or-nothing, so a manual `phpdismod`
                            leaves this reading "off" while the extension is still
                            live for websites — site works, cron job fails. Rare
                            enough that it costs a taller row only when it fires. */}
                        {driftOf(extension) ? (
                          <span className="mt-1 flex items-center justify-end gap-1.5 text-xs text-warning">
                            <TriangleAlert className="size-3.5 shrink-0" />
                            {t(`extensions.${driftOf(extension)}`)}
                          </span>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
            </div>
            {/* The cut-off row at the bottom looked like a rendering fault. */}
            <div className="pointer-events-none absolute inset-x-px bottom-px h-8 rounded-b-lg bg-gradient-to-t from-background to-transparent" />
          </div>
        )}

        {/* The ones compiled into PHP. Kept — searching for `json` should find
            it and be told why there is no switch — but folded away, because
            there is nothing to decide about any of them. */}
        {builtins.length > 0 ? (
          <details className="group rounded-lg border">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 text-sm text-muted-foreground hover:text-foreground">
              <span>{t("extensions.builtinTitle", { count: builtins.length })}</span>
              <ChevronDown className="size-4 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="border-t px-3 py-2">
              <p className="pb-2 text-xs text-muted-foreground">{t("extensions.builtinBody")}</p>
              <ul className="flex flex-wrap gap-1.5">
                {builtins.map((extension) => (
                  <li
                    key={extension.name}
                    className="rounded-md bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                  >
                    {extension.name}
                  </li>
                ))}
              </ul>
            </div>
          </details>
        ) : null}
      </CardContent>
    </Card>
  );
}
