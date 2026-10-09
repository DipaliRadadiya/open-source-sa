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
import { NodeVersionDialog } from "@/components/applications/node-version-dialog";
import { AutoRefresh } from "@/components/ui/auto-refresh";

// Facts that do not apply to the site type are left out, not shown as "—".
// Full class strings for Tailwind; 3 or 4 columns keeps the last row full (6 or 8 facts).
const FACT_COLUMNS = { 3: "xl:grid-cols-3", 4: "xl:grid-cols-4" };

function factColumns(count) {
  if (count % 4 === 0) return FACT_COLUMNS[4];
  if (count % 3 === 0) return FACT_COLUMNS[3];
  return FACT_COLUMNS[4];
}

function Fact({ icon: Icon, label, value, mono, copy, onEdit, editLabel, action, note, noteTone, menu, menuLabel, menuBusy = false, plain = false, strip = false }) {
  const noteNode = note ? (
    // Not truncated: a filename or refusal is the content.
    <p className={`mt-0.5 text-xs leading-snug ${noteTone === "warning" ? "text-[color-mix(in_oklch,var(--warning)_75%,var(--foreground))] dark:text-warning" : "text-muted-foreground"}`}>
      {note}
    </p>
  ) : null;
  const controls = (
    <>
      {/* Outlined, like every other button: as bare icons they read as decoration (7 Oct). */}
      {copy ? <CopyButton value={String(value)} className="border border-border/80 bg-card shadow-xs" /> : null}
      {/* One menu when the tile has several actions (probe, set type by hand). */}
      {menu?.length ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="icon-sm"
              /* `menuBusy` too: a menu action has no Review button to show it is running. */
              disabled={menuBusy || action?.busy}
              aria-label={menuLabel}
              title={menuLabel}
              className="size-7 shrink-0"
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

      {/* `text` gives an actionable finding a labelled button; optional probes stay an icon. */}
      {action ? (
        action.text ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={action.onClick}
            disabled={action.busy}
            title={action.label}
            className="h-7 shrink-0"
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
            variant="outline"
            size="icon-sm"
            onClick={action.onClick}
            disabled={action.busy}
            aria-label={action.label}
            title={action.label}
            className="size-7 shrink-0"
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
          variant="outline"
          size="icon-sm"
          onClick={onEdit}
          aria-label={editLabel}
          className="size-7 shrink-0"
        >
          <Pencil className="size-3.5" />
        </Button>
      ) : null}
    </>
  );

  // `strip`: a label over its value, no box, for the facts row in the page header.
  if (strip) {
    return (
      <div className="min-w-0">
        <dt className="text-xs text-muted-foreground">{label}</dt>
        <dd className="flex min-h-7 min-w-0 items-center gap-1">
          <span
            className={`min-w-0 truncate text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
            title={typeof value === "string" ? value : undefined}
          >
            {value}
          </span>
          {controls}
        </dd>
        {noteNode ? <dd>{noteNode}</dd> : null}
      </div>
    );
  }

  // `plain`: one compact row, label left and value right, as the prototype's Details card.
  if (plain) {
    return (
      <div className="min-w-0 py-2.5">
        <div className="flex min-w-0 items-center gap-3">
          <Icon className="size-4 shrink-0 text-muted-foreground" />
          <span className="shrink-0 text-sm text-muted-foreground">{label}</span>
          <span
            className={`min-w-0 flex-1 truncate text-right text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
            title={typeof value === "string" ? value : undefined}
          >
            {value}
          </span>
          {controls}
        </div>
        {noteNode ? <div className="ps-7">{noteNode}</div> : null}
      </div>
    );
  }

  return (
    // min-w-0: a grid item defaults to min-width:auto, so `truncate` would never fire.
    <div className="flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5">
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p
          className={`truncate text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
        >
          {value}
        </p>
        {noteNode}
      </div>
      {controls}
    </div>
  );
}

// `strip`: no card, just the facts in a row, for the application header (Krishna, 7 Oct:
// the details are what people open this page for, so they lead it).
export function SiteFactsCard({ application, canManage = false, siteTypes = [], nodeVersions = [], nodeVersionsFailed = false, columns = null, list = false, strip = false, className }) {
  const t = useTranslations("applications");
  const format = useFormatter();
  const [editingWebRoot, setEditingWebRoot] = useState(false);
  const [editingNode, setEditingNode] = useState(false);
  const nodeChange = application.node_version_change ?? null;
  const nodeSwitching = nodeChange?.status === "switching";
  const nodeRange = siteTypes.find((type) => type.name === application.site_type)?.node_version_range ?? null;
  const [measuring, setMeasuring] = useState(false);
  const [detecting, setDetecting] = useState(false);
  const [refreshing, startRefresh] = useTransition();
  // True through the request and the re-read that shows its result.
  const probing = detecting || refreshing;
  const [relabelTo, setRelabelTo] = useState(null);
  const router = useRouter();
  const { refreshAndWait } = useRefresh();

  // On click, never on mount: files often arrive later, so an automatic probe records "nothing found"
  // too early. Throttled 10/min server-side.
  async function detect() {
    setDetecting(true);
    try {
      const { data } = await detectApplicationSiteType(application.id);

      // Toast the verdict from the response; the card still re-reads for the stored copy.
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

      // A transition keeps `refreshing` true until the new data shows (`router.refresh()` cannot be awaited).
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

  // Shows `matched` (a checkable file) rather than `confidence`; an empty probe says when it ran.
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
    // Recognised but nothing to offer: state what it saw without judging the current type.
    if (detectionState === "recognised") {
      const type = detection?.detected_title ?? detection?.detected;
      return detection?.matched
        ? t("siteTypeDetection.checkedFoundFile", { type, file: detection.matched })
        : t("siteTypeDetection.checkedFound", { type });
    }
    if (detectionState === "found") return t("siteTypeDetection.nothingFound");
    return null;
  })();

  // Type titles come from the catalog (not in frontend i18n); without one, no targets are offered.
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

  // Walks every inode, so only ever on the user's click.
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

  // Null when unmeasured: shown as "Not measured", never "0 B".
  const size = formatBytes(application.directory_size_bytes, format);

  // What share of that is in Docker volumes, for a container site. The total on
  // its own is unexplainable: 284 MB against a document root the File Manager
  // shows as almost empty reads as a bug in the panel, and the volumes are where
  // a container site keeps everything it owns.
  //
  // Null — not 0 — means the site has no volumes to measure, so every PHP, Node
  // and static site gets no second line rather than "0 B in volumes".
  const volumeSize =
    application.volume_size_bytes === null ||
    application.volume_size_bytes === undefined
      ? null
      : formatBytes(application.volume_size_bytes, format);

  const facts = [
    {
      icon: Package,
      label: t("columns.type"),
      value: application.site_type_title ?? application.site_type,
      note: typeNote,
      // The backend refuses to probe git sites. Only a suggestion gets its own button.
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
      // Not in `list`: the header already says it.
      value: list ? null : !application.serving_profile || application.serving_profile === "php" ? application.php_version : null,
      mono: true,
    },
    {
      icon: Hexagon,
      label: t("facts.node"),
      value: application.node_version,
      mono: true,
      // The failure message arrives translated; the site was put back and still runs.
      note: nodeSwitching
        ? t("nodeVersion.switching", { target: nodeChange.target ?? "" })
        : nodeChange?.status === "failed"
          ? nodeChange.message
          : null,
      noteTone: nodeChange?.status === "failed" ? "warning" : null,
      action: nodeSwitching ? { busy: true, label: t("nodeVersion.switching", { target: nodeChange.target ?? "" }), icon: Hexagon } : null,
      onEdit: canManage && !nodeSwitching ? () => setEditingNode(true) : null,
      editLabel: t("nodeVersion.title"),
    },
    { icon: Plug, label: t("facts.port"), value: application.app_port, mono: true, copy: true },
    {
      icon: HardDrive,
      label: t("columns.size"),
      value: size ?? t("size.notMeasured"),
      // Only once there is a measurement to break down. A site nobody has
      // measured says "Not measured", and hanging "0 B in volumes" under that
      // would be describing a number that is not there.
      note: size && volumeSize ? t("size.inVolumes", { size: volumeSize }) : null,
      action: canManage
        ? { onClick: measure, busy: measuring, label: t("size.measureHint"), icon: Ruler }
        : null,
    },
    // Not with `columns={2}`: the page header already shows when it was created.
    (columns === 2 || list) && !strip ? null : { icon: CalendarClock, label: t("columns.created"), value: application.created_at_human },
  ].filter((fact) => fact && fact.value !== null && fact.value !== undefined && fact.value !== "");

  const dialogs = (
    <>
      {/* Until the switch settles: `node_version_change` goes back to null, or to failed. */}
      {nodeSwitching ? <AutoRefresh intervalMs={3000} stopAfterMs={600000} /> : null}

      {application.node_version ? (
        <NodeVersionDialog
          application={application}
          range={nodeRange}
          versions={nodeVersions}
          versionsFailed={nodeVersionsFailed}
          open={editingNode}
          onOpenChange={setEditingNode}
        />
      ) : null}

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
        /* Type titles are not in frontend i18n. No example lookup call here: check-i18n greps
           comments and would flag it as an unresolved key. */
        targetTitle={relabelTitle}
        matched={relabelTo === suggestion ? detection?.matched : null}
      />
    </>
  );

  if (strip) {
    return (
      <>
        {/* One row up to six facts; seven or eight go four across, so none is left alone. */}
        <dl className={`grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 ${facts.length > 6 ? "xl:grid-cols-4" : "xl:grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))]"} ${className ?? ""}`}>
          {facts.map((fact) => (
            <Fact key={fact.label} editLabel={t("webRoot.title")} strip {...fact} />
          ))}
        </dl>
        {dialogs}
      </>
    );
  }

  return (
    <Card className={className}>
      <CardHeader>
        <CardTitle as="h2" className="flex items-center gap-2">
          {t("facts.title")}
        </CardTitle>
        <CardDescription>{t("facts.description")}</CardDescription>
      </CardHeader>
      {/* `columns={2}`: the card shares its row with another, so it never goes four across. */}
      {/* `list`: a plain one-column list for the narrow reference column. */}
      <CardContent
        className={
          list
            ? "divide-y divide-border/70 py-0"
            : `grid gap-2 sm:grid-cols-2 ${columns === 2 ? "sm:[&>*:last-child:nth-child(odd)]:col-span-2" : factColumns(facts.length)}`
        }
      >
        {facts.map((fact) => (
          <Fact key={fact.label} editLabel={t("webRoot.title")} plain={list} {...fact} />
        ))}
      </CardContent>

      {dialogs}
    </Card>
  );
}
