"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
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
 * What this site is and where it lives.
 *
 * Tiles rather than rows, matching the server dashboard's identity band: icon,
 * a small uppercase label, then the value on a tinted surface. Four flat white
 * cards of plain text is the "identical feature card" tell — the page reads as
 * boilerplate because nothing on it has any texture or weight. The tiles also
 * give the panel one visual language across its two dashboards instead of two.
 *
 * Every value is a fact somebody might have to quote, so paths and versions are
 * monospaced and the ones people paste carry a copy control. A fact that does
 * not apply to this site type is left out rather than shown as "—".
 */
/*
 * Tailwind needs the full class string at build time, so the column counts are
 * a lookup rather than a template. Only 3 and 4 are offered: the fact count
 * varies by site type — 6 for WordPress and Git sites, 8 when a Node runtime
 * adds a version and a port — and picking whichever divides evenly is what
 * keeps the last row full. Six tiles in a 4-column grid left half a row empty,
 * which reads as a card that failed to finish loading.
 */
const FACT_COLUMNS = { 3: "xl:grid-cols-3", 4: "xl:grid-cols-4" };

function factColumns(count) {
  if (count % 4 === 0) return FACT_COLUMNS[4];
  if (count % 3 === 0) return FACT_COLUMNS[3];
  return FACT_COLUMNS[4];
}

function Fact({ icon: Icon, label, value, mono, copy, onEdit, editLabel, action, note, menu, menuLabel }) {
  return (
    // min-w-0: a grid item keeps min-width:auto, so without it the tile grows to
    // its longest word and `truncate` never fires.
    <div className="flex min-w-0 items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5">
      <Icon className="size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 flex-1">
        <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
        <p
          className={`truncate text-sm font-medium ${mono ? "font-mono text-[13px] tabular-nums" : ""}`}
        >
          {value}
        </p>
        {/* A second line for the one fact that has something to add about
            itself. It makes this tile taller than its neighbours and therefore
            its whole grid row — accepted, because the alternative is a finding
            nobody sees. NOT truncated: a filename or a refusal is the content,
            and clipping it would leave the note saying less than nothing. */}
        {note ? <p className="mt-0.5 text-xs leading-snug text-muted-foreground">{note}</p> : null}
      </div>
      {copy ? <CopyButton value={String(value)} /> : null}
      {/* A menu, when the tile has more than one thing you could do to it —
          run the probe, or set the type back by hand. One control either way;
          the alternative was two icon buttons crammed into a 75px tile. */}
      {menu?.length ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="icon-sm"
              disabled={action?.busy}
              aria-label={menuLabel}
              title={menuLabel}
              className="shrink-0"
            >
              {action?.busy ? (
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

      {/* Two weights, and which one a tile gets is not cosmetic.

          `text` promotes the control to a labelled button. A fact that has
          just told you something actionable — "Looks like WordPress" — and
          then offers a 32px transparent icon in the far corner is inviting a
          decision and hiding the way to make it. An OPTIONAL probe (Measure,
          Detect-when-nothing-is-known) is the opposite: nobody came here for
          it, so it stays quiet and out of the way. */}
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
              // Defaults to the ruler because Measure was the only action here
              // when this slot was written; Detect brings its own.
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
  const [relabelTo, setRelabelTo] = useState(null);
  const router = useRouter();

  /*
   * Read the site's own directory and say what is in it.
   *
   * Button-triggered, never on mount, and that is the backend's call as much
   * as this side's: a site's files arrive AFTER it is created — somebody makes
   * a Custom PHP site and then uploads WordPress into it — so probing when the
   * page opens would run against an empty directory and record "nothing
   * found" at the one moment that answer is guaranteed to be wrong. Plesk's WP
   * Toolkit and Softaculous both make you press Scan for the same reason.
   *
   * Throttled 10/min server-side, hence `disabled` while in flight rather than
   * trusting nobody to double-click.
   */
  async function detect() {
    setDetecting(true);
    try {
      await detectApplicationSiteType(application.id);
      // Re-read rather than merging the response: the verdict is stored on the
      // application, and the page's own fetch is the one source for it.
      router.refresh();
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
   * What the Type tile adds about itself, if anything.
   *
   * The `found` branch splits in two, and the second half is the reason this
   * feature needed research: Softaculous's support board has recurring threads
   * titled "Scan says no installations found", because a probe that reports
   * nothing and does not SAY so is indistinguishable from a button that did
   * not work. So a probe that found nothing says so, with when.
   *
   * `matched` — the file — rather than `confidence`. The backend put that
   * field there so the note could say why, and "wp-config.php found" is
   * checkable where "confidence 95" is a number nobody can act on.
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
    if (detectionState === "found") return t("siteTypeDetection.nothingFound");
    return null;
  })();

  /*
   * Probe, and set the type back by hand.
   *
   * The second half exists because the confirm dialog promises "you can set it
   * back at any time" — which was true of the API and false of the panel,
   * since nothing offered the reverse. A promise the product does not keep is
   * worse than no promise, so either the sentence went or the control arrived.
   *
   * Titles come from the catalog, never from our messages: the type names are
   * not in the frontend's i18n at all. No catalog (an older payload, a failed
   * fetch) means the targets are simply not offered rather than listed by
   * their internal names.
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
   * "Not measured" is honest but it is a dead end — the answer lived four
   * clicks away in the row menu of a different screen. Walking every inode is
   * still the user's decision, never a side effect of opening this page, so it
   * is a control on the tile rather than something the card does on its own.
   */
  async function measure() {
    setMeasuring(true);
    try {
      await measureApplicationSize(application.id);
      router.refresh();
    } catch (error) {
      // Throttled, and it refuses outright for a site with no directory on
      // disk — both are real answers worth passing on verbatim.
      toast.error(apiMessage(error, t("size.measureFailed")));
    } finally {
      setMeasuring(false);
    }
  }

  // The one number here that changes on its own. formatBytes returns null for a
  // site nobody has measured — "Not measured" is the honest answer, and it is
  // the same word the sites list uses, where the ⋯ menu can do something about
  // it. A tile showing "0 B" for an unmeasured site would be a lie.
  const size = formatBytes(application.directory_size_bytes, format);

  const facts = [
    {
      icon: Package,
      label: t("columns.type"),
      value: application.site_type_title ?? application.site_type,
      note: typeNote,
      /*
       * Offered on every non-git site, not only the generic ones that could be
       * relabelled upward. Reading the disk is information either way — a
       * marketplace site whose files have been replaced is exactly the case
       * worth checking — and the narrowing escape hatch is open to all of them.
       *
       * Git is the one exclusion, and it is absolute: the backend refuses to
       * probe a git site at all, because its type can never change, so every
       * verdict would be a finding nothing may act on.
       */
      /*
       * A suggestion is the only thing that gets its own button. Everything
       * else about the type is optional housekeeping and lives in the menu —
       * otherwise a tile that has nothing to tell you still shows a control
       * competing with the one that does.
       */
      action:
        canManage && suggestion
          ? {
              onClick: () => setRelabelTo(suggestion),
              busy: detecting,
              label: t("siteTypeDetection.reviewHint"),
              // Labelled because it is an invitation. A 32px transparent icon
              // beside "Looks like WordPress" asked for a decision and hid the
              // way to make it.
              text: t("siteTypeDetection.reviewAction"),
              icon: ScanSearch,
            }
          : null,
      menuLabel: t("siteTypeDetection.menuHint"),
      menu: typeMenu,
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
    { icon: FileCode, label: t("facts.php"), value: application.php_version, mono: true },
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
         * From the API's own `detected_title`, never from our messages: type
         * titles are not in the frontend's i18n at all — they come back
         * translated on the payload (`site_type_title`, and `title` on the
         * catalog). A lookup under a `types.<name>` key would simply miss —
         * and note it cannot be written out here as a call either, because
         * check-i18n greps for that shape without stripping comments and
         * reports the example as a real unresolved key.
         *
         * Which is also why the manual narrowing hatch is NOT wired here yet:
         * naming "Custom PHP" as a target needs the site-types catalog on this
         * card, and that is plumbing worth doing deliberately rather than
         * smuggling into this pass.
         */
        targetTitle={relabelTitle}
        matched={relabelTo === suggestion ? detection?.matched : null}
      />
    </Card>
  );
}
