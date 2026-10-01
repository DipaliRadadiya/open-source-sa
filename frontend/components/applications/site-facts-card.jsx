"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import {
  CalendarClock,
  FileCode,
  FolderTree,
  HardDrive,
  Hexagon,
  Info,
  Package,
  Loader2,
  Pencil,
  Plug,
  MoreHorizontal,
  Ruler,
  ScanSearch,
  Tags,
  User,
} from "lucide-react";
import { detectApplicationSiteType, measureApplicationSize } from "@/lib/api/applications";
import {
  canDetectSiteType,
  narrowingTargets,
  siteTypeDetectionState,
  suggestedSiteType,
} from "@/lib/applications/site-type-detection";
import { SiteTypeRelabelDialog } from "@/components/applications/site-type-relabel-dialog";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { WebRootDialog } from "@/components/applications/web-root-dialog";

/**
 * What this site is and where it lives, as tiles matching the server
 * dashboard's identity band. Paths and versions are monospaced, pasteable ones
 * carry a copy control, and facts that do not apply to the site type are left
 * out rather than shown as "—".
 */
/*
 * A lookup, not a template: Tailwind needs full class strings at build time.
 * The fact count varies by site type (6 or 8), so pick 3 or 4 columns to keep
 * the last row full.
 */
const FACT_COLUMNS = { 3: "xl:grid-cols-3", 4: "xl:grid-cols-4" };

function factColumns(count) {
  if (count % 4 === 0) return FACT_COLUMNS[4];
  if (count % 3 === 0) return FACT_COLUMNS[3];
  return FACT_COLUMNS[4];
}

function Fact({ icon: Icon, label, value, mono, copy, onEdit, editLabel, action, note, menu, menuLabel, menuBusy = false }) {
  return (
    // min-w-0: a grid item defaults to min-width:auto, so `truncate` would never fire.
    <div className="flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5">
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
        <p
          className={`truncate text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
        >
          {value}
        </p>
        {/* Optional second line; not truncated, since a filename or refusal is the
            content. */}
        {note ? <p className="mt-0.5 text-xs leading-snug text-muted-foreground">{note}</p> : null}
      </div>
      {copy ? <CopyButton value={String(value)} /> : null}
      {/* One menu when the tile has several actions (probe, set type by hand). */}
      {menu?.length ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              /* `menuBusy` too: an action started from this menu has no Review button to
                 show it is running. */
              disabled={menuBusy || action?.busy}
              aria-label={menuLabel}
              title={menuLabel}
              className="shrink-0"
            >
              {menuBusy || action?.busy ? (
                <Loader2 className="size-3.5 animate-spin" />
              ) : (
                <MoreHorizontal className="size-3.5" />
              )}
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {menu.map((item) => (
              <DropdownMenuItem key={item.key} onSelect={item.onSelect}>
                <item.icon className="size-3.5" />
                {item.label}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      ) : null}

      {/* `text` makes a labelled button for an actionable finding ("Looks like
          WordPress"); optional probes stay a quiet icon. */}
      {action ? (
        action.text ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={action.onClick}
            disabled={action.busy}
            title={action.label}
            className="shrink-0"
          >
            {action.busy ? (
              <Loader2 className="size-3.5 animate-spin" />
            ) : (
              <action.icon className="size-3.5" />
            )}
            {action.text}
          </Button>
        ) : (
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={action.onClick}
            disabled={action.busy}
            aria-label={action.label}
            title={action.label}
            className="shrink-0"
          >
            {action.busy ? (
              <Loader2 className="size-3.5 animate-spin" />
            ) : (
              // Defaults to the ruler (Measure); Detect brings its own icon.
              <action.icon className="size-3.5" />
            )}
          </Button>
        )
      ) : null}
      {onEdit ? (
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={onEdit}
          aria-label={editLabel}
          className="shrink-0"
        >
          <Pencil className="size-3.5" />
        </Button>
      ) : null}
    </div>
  );
}

export function SiteFactsCard({ application, canManage = false, siteTypes = [], className }) {
  const t = useTranslations("applications");
  const format = useFormatter();
  const [editingWebRoot, setEditingWebRoot] = useState(false);
  const [measuring, setMeasuring] = useState(false);
  const [detecting, setDetecting] = useState(false);
  const [refreshing, startRefresh] = useTransition();
  // True through the request and the re-read that shows its result.
  const probing = detecting || refreshing;
  const [relabelTo, setRelabelTo] = useState(null);
  const router = useRouter();
  const { refreshAndWait } = useRefresh();

  /*
   * Probe the site's directory for its type. Button-triggered, never on mount:
   * files often arrive after the site is created, so an automatic probe would
   * record "nothing found" too early. Throttled 10/min server-side, hence
   * disabled while in flight.
   */
  async function detect() {
    setDetecting(true);
    try {
      const { data } = await detectApplicationSiteType(application.id);

      // Toast the verdict straight from the response, so the click is acknowledged;
      // the card still re-reads for the stored copy.
      const found = data?.site_type_detection;
      const type = found?.detected_title ?? found?.detected;
      if (found?.suggested) {
        toast.success(t("siteTypeDetection.detectedSuggestion", { type }));
      } else if (type) {
        toast.success(
          found.matched
            ? t("siteTypeDetection.detectedFile", { type, file: found.matched })
            : t("siteTypeDetection.detected", { type }),
        );
      } else {
        // Not an error: an empty directory is a real answer.
        toast.info(t("siteTypeDetection.detectedNothing"));
      }

      /*
       * Re-read rather than merge: the verdict is stored on the application. In a
       * transition so `refreshing` stays true until the new data is on screen
       * (`router.refresh()` cannot be awaited).
       */
      startRefresh(() => router.refresh());
    } catch (err) {
      toast.error(apiMessage(err, t("siteTypeDetection.detectFailed")));
    } finally {
      setDetecting(false);
    }
  }

  const detection = application.site_type_detection;
  const detectionState = siteTypeDetectionState(application);
  const suggestion = suggestedSiteType(application);

  /*
   * The Type tile's note. A probe that found nothing says so, with when, so it
   * is distinguishable from a button that did nothing. Shows `matched` (the
   * file), which is checkable, rather than `confidence`.
   */
  const typeNote = (() => {
    if (detectionState === "suggested") {
      return detection?.matched
        ? t("siteTypeDetection.looksLikeBecause", {
            type: detection.detected_title ?? suggestion,
            file: detection.matched,
          })
        : t("siteTypeDetection.looksLike", {
            type: detection.detected_title ?? suggestion,
          });
    }
    /*
     * Recognised something with nothing to offer (usually the label is already
     * right). States what it saw without judging, so it stays true whether or not
     * it matches the current type.
     */
    if (detectionState === "recognised") {
      const type = detection?.detected_title ?? detection?.detected;
      return detection?.matched
        ? t("siteTypeDetection.checkedFoundFile", { type, file: detection.matched })
        : t("siteTypeDetection.checkedFound", { type });
    }
    if (detectionState === "found") return t("siteTypeDetection.nothingFound");
    return null;
  })();

  /*
   * Probe, and set the type back by hand (the confirm dialog promises this is
   * possible). Titles come from the catalog, as type names are not in the
   * frontend's i18n; without a catalog the targets are not offered.
   */
  const typeMenu = (() => {
    if (!canManage || !canDetectSiteType(application)) return [];

    const items = [
      {
        key: "detect",
        icon: ScanSearch,
        label: t("siteTypeDetection.detectAction"),
        onSelect: detect,
      },
    ];

    for (const name of narrowingTargets(application)) {
      const title = siteTypes.find((type) => type.name === name)?.title;
      if (!title) continue;
      items.push({
        key: `to-${name}`,
        icon: Tags,
        label: t("siteTypeDetection.changeTo", { type: title }),
        onSelect: () => setRelabelTo(name),
      });
    }

    return items;
  })();

  // Resolved once, so the dialog names the target the same way the menu did.
  const relabelTitle =
    relabelTo === detection?.detected
      ? (detection?.detected_title ?? relabelTo)
      : (siteTypes.find((type) => type.name === relabelTo)?.title ?? relabelTo);

  /*
   * Measuring walks every inode, so it is always the user's choice, never a side
   * effect of opening the page.
   */
  async function measure() {
    setMeasuring(true);
    try {
      await measureApplicationSize(application.id);
      // Toast on success too: a re-measure can return the same number.
      await refreshAndWait();
      toast.success(t("size.measured"));
    } catch (error) {
      // Throttled and refused for a site with no directory; pass the API's message on.
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  // Null for an unmeasured site: shown as "Not measured" (as in the sites
  // list), never "0 B".
  const size = formatBytes(application.directory_size_bytes, format);

  const facts = [
    {
      icon: Package,
      label: t("columns.type"),
      value: application.site_type_title ?? application.site_type,
      note: typeNote,
      /*
       * Detection is offered on every non-git site; the backend refuses to probe git
       * sites since their type can never change. Only a suggestion gets its own
       * button; everything else lives in the menu.
       */
      action:
        canManage && suggestion
          ? {
              onClick: () => setRelabelTo(suggestion),
              busy: probing,
              label: t("siteTypeDetection.reviewHint"),
              // Labelled because it is an invitation to decide.
              text: t("siteTypeDetection.reviewAction"),
              icon: ScanSearch,
            }
          : null,
      menuLabel: t("siteTypeDetection.menuHint"),
      menu: typeMenu,
      menuBusy: probing,
    },
    { icon: User, label: t("columns.owner"), value: application.system_user?.username, mono: true },
    {
      icon: FolderTree,
      label: t("facts.webRoot"),
      value: application.web_root,
      mono: true,
      copy: true,
      // The one fact on this card that is a setting rather than a record.
      onEdit: canManage ? () => setEditingWebRoot(true) : null,
    },
    // Hidden for Node or static sites: they carry a php_version they never run.
    {
      icon: FileCode,
      label: t("facts.php"),
      value: !application.serving_profile || application.serving_profile === "php" ? application.php_version : null,
      mono: true,
    },
    { icon: Hexagon, label: t("facts.node"), value: application.node_version, mono: true },
    { icon: Plug, label: t("facts.port"), value: application.app_port, mono: true, copy: true },
    {
      icon: HardDrive,
      label: t("columns.size"),
      value: size ?? t("size.notMeasured"),
      action: canManage
        ? { onClick: measure, busy: measuring, label: t("size.measureHint"), icon: Ruler }
        : null,
    },
    { icon: CalendarClock, label: t("columns.created"), value: application.created_at_human },
  ].filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle as="h2" className="flex items-center gap-2 text-lg font-semibold">
          <Info className="size-4 text-primary" />
          {t("facts.title")}
        </CardTitle>
        <CardDescription>{t("facts.description")}</CardDescription>
      </CardHeader>
      <CardContent className={`grid gap-2 sm:grid-cols-2 ${factColumns(facts.length)}`}>
        {facts.map((fact) => (
          <Fact key={fact.label} {...fact} editLabel={t("webRoot.title")} />
        ))}
      </CardContent>

      <WebRootDialog
        application={application}
        open={editingWebRoot}
        onOpenChange={setEditingWebRoot}
      />

      <SiteTypeRelabelDialog
        open={Boolean(relabelTo)}
        onOpenChange={(next) => setRelabelTo(next ? relabelTo : null)}
        application={application}
        target={relabelTo}
        /*
         * From the API's `detected_title`, never from frontend messages: type titles are
         * not in the frontend's i18n. Do not write an example lookup call here either:
         * check-i18n greps comments and would report it as an unresolved key.
         */
        targetTitle={relabelTitle}
        matched={relabelTo === suggestion ? detection?.matched : null}
      />
    </Card>
  );
}
