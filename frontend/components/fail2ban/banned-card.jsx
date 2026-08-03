"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import Link from "next/link";
import { ShieldOff, Ban, TriangleAlert, ScrollText } from "lucide-react";
import { unbanIp, unbanAll } from "@/lib/api/fail2ban";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DataTable } from "@/components/ui/data-table";
import { EmptyState } from "@/components/data-table/empty-state";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { BanIpDialog } from "@/components/fail2ban/ban-ip-dialog";
import { BannedCards } from "@/components/fail2ban/banned-cards";
import { apiMessage } from "@/lib/api/error-message";

// 10, not 25: on a phone each ban is a card, and 25 of them is a wall of
// scrolling with the paging controls stranded at the bottom — the same problem
// the desktop scroll box solves, which cards cannot use without nesting a
// second scroller inside the page.
const PAGE_SIZE = 10;
// Below this the list is still scannable and a toolbar is just more to read.
const TOOLS_FROM = 8;

/* Cells at module level — flexRender treats a cell function's identity as the
 * component type, so inline cells remount on every render. */

function IpCell({ row }) {
  return <span className="font-mono text-sm">{row.original.ip}</span>;
}

function JailCell({ row }) {
  return (
    <Badge variant="outline" className="font-normal">
      {row.original.jail}
    </Badge>
  );
}

function BannedAtCell({ row }) {
  return (
    <span className="whitespace-nowrap text-xs text-muted-foreground">
      {row.original.banned_at || "—"}
    </span>
  );
}

/**
 * How long is left, which is the whole reason this column exists: a list of bare
 * addresses gives you no way to choose between waiting and unbanning.
 *
 * Three different answers, and conflating any two of them misleads:
 *   - a countdown, when the server gave us seconds
 *   - **Permanent**, when the server dated the ban but named no expiry
 *   - **Unknown**, when it reported no timing at all — an older fail2ban simply
 *     doesn't tell us, and "we couldn't ask" must never render as "never
 *     expires". Someone would sit waiting for a ban to lift on its own.
 */
function ExpiryCell({ row, table }) {
  return expiryContent(row.original, table.options.meta.t);
}

// Shared with the mobile cards: the same three-way distinction has to hold at
// every width, or the phone quietly tells a different story to the desktop.
function expiryContent(ban, t) {
  const { seconds_left: left, expires_at: expires, banned_at: since } = ban;

  if (typeof left === "number") {
    if (left <= 0) return <span className="text-muted-foreground">{t("banned.expiring")}</span>;
    return <span className="whitespace-nowrap tabular-nums">{formatLeft(t, left)}</span>;
  }

  // No seconds, but the server named an expiry — show it verbatim, server clock.
  if (expires) {
    return <span className="whitespace-nowrap text-xs tabular-nums">{expires}</span>;
  }

  // No expiry AND no start date means the source told us nothing, not that the
  // ban lasts forever.
  if (!since) return <span className="text-muted-foreground">{t("banned.unknownLeft")}</span>;

  return (
    <Badge variant="secondary" className="font-normal">
      {t("banned.permanent")}
    </Badge>
  );
}

function ActionsCell({ row, table }) {
  const { canManage, onUnban, unbanning, t } = table.options.meta;
  return (
    <div className="flex justify-end">
      <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
        <Button
          variant="ghost"
          size="sm"
          disabled={!canManage || unbanning === row.original.ip}
          onClick={() => onUnban(row.original)}
        >
          <ShieldOff className="size-4" />
          {t("banned.unban")}
        </Button>
      </ReasonTooltip>
    </div>
  );
}

export function BannedCard({ banned, jails, canManage, logHref }) {
  const t = useTranslations("fail2ban");
  const router = useRouter();
  const [unbanning, setUnbanning] = useState(null);
  const [confirmAll, setConfirmAll] = useState(false);
  const [clearing, setClearing] = useState(false);
  const [query, setQuery] = useState("");
  const [jailFilter, setJailFilter] = useState("all");
  const [rawPage, setRawPage] = useState(0);

  const needsTools = banned.length > TOOLS_FROM;
  const jailOptions = [...new Set(banned.map((b) => b.jail))].sort();

  const term = query.trim().toLowerCase();
  const filtered = banned.filter(
    (b) =>
      (jailFilter === "all" || b.jail === jailFilter) &&
      (!term || b.ip.toLowerCase().includes(term)),
  );

  // Clamped during render rather than reset in an effect: filtering down to two
  // rows while sitting on page 4 would otherwise show an empty table for a beat.
  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const page = Math.min(rawPage, pageCount - 1);
  const visible = filtered.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE);
  const setPage = setRawPage;

  async function onUnban(ban) {
    setUnbanning(ban.ip);
    try {
      await unbanIp(ban.ip, ban.jail);
      toast.success(t("banned.unbanned", { ip: ban.ip }));
      router.refresh();
    } catch (error) {
      // 404 = not banned anywhere. Not a success: the list is out of date, so
      // reload it rather than claiming we released something.
      if (error.response?.status === 404) {
        toast.info(t("banned.alreadyGone"));
        router.refresh();
        return;
      }
      const data = error.response?.data;
      toast.error([apiMessage(error, t("banned.failed")), data?.reference].filter(Boolean).join(" · "));
    } finally {
      setUnbanning(null);
    }
  }

  async function onUnbanAll() {
    setClearing(true);
    try {
      const { data } = await unbanAll();
      toast.success(t("banned.unbannedAll", { count: data?.unbanned?.ips?.length ?? 0 }));
      setConfirmAll(false);
      router.refresh();
    } catch (error) {
      if (error.response?.status === 404) {
        toast.info(t("banned.noneToClear"));
        setConfirmAll(false);
        router.refresh();
        return;
      }
      const data = error.response?.data;
      toast.error([apiMessage(error, t("banned.failed")), data?.reference].filter(Boolean).join(" · "));
    } finally {
      setClearing(false);
    }
  }

  const columns = [
    { accessorKey: "ip", header: t("banned.ip"), cell: IpCell },
    { accessorKey: "jail", header: t("banned.jail"), cell: JailCell },
    { accessorKey: "banned_at", header: t("banned.since"), cell: BannedAtCell },
    { id: "expiry", header: t("banned.timeLeft"), cell: ExpiryCell },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("banned.actions")}</span>,
      meta: { className: "text-right" },
      cell: ActionsCell,
    },
  ];

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <CardTitle className="text-base font-semibold">{t("banned.title")}</CardTitle>
          <CardDescription>{t("banned.description")}</CardDescription>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {/* A ban tells you an address was blocked, never what it did. The
              lines that triggered it are in the log, so the page says where. */}
          {logHref ? (
            <Button variant="ghost" size="sm" asChild>
              <Link href={logHref}>
                <ScrollText className="size-4" />
                {t("banned.seeLog")}
              </Link>
            </Button>
          ) : null}
          <BanIpDialog jails={jails} canManage={canManage} />
          {/* Kept in plain sight: this exists for the moment you've just banned
              your own office, and that is not a moment for hunting through a
              menu or unbanning rows one at a time. */}
          {banned.length > 0 ? (
            <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
              <Button
                variant="outline"
                size="sm"
                disabled={!canManage}
                onClick={() => setConfirmAll(true)}
              >
                {t("banned.unbanAll")}
              </Button>
            </ReasonTooltip>
          ) : null}
        </div>
      </CardHeader>

      <CardContent className="space-y-3">
        {/* Search and paging only once the list stops being scannable. Three
            bans need no toolbar; a hundred are unusable without one, and this
            table is only ever long on the day something is going wrong. */}
        {needsTools ? (
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t("banned.search")}
              className="sm:max-w-64"
              autoComplete="off"
              spellCheck={false}
            />
            {jailOptions.length > 1 ? (
              <Select value={jailFilter} onValueChange={setJailFilter}>
                <SelectTrigger className="sm:w-52">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t("banned.allJails")}</SelectItem>
                  {jailOptions.map((name) => (
                    <SelectItem key={name} value={name}>
                      {name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : null}
          </div>
        ) : null}

        {banned.length === 0 ? (
          <EmptyState
            icon={Ban}
            title={t("banned.emptyTitle")}
            description={t("banned.emptyBody")}
          />
        ) : visible.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">
            {t("banned.noMatches")}
          </p>
        ) : (
          <>
            {/* Cards on a phone, table from lg. Five columns and a full
                timestamp cannot fit 390px, and the horizontal scroll hides
                Unban off the right edge. */}
            <div className="lg:hidden">
              <BannedCards
                data={visible}
                canManage={canManage}
                onUnban={onUnban}
                unbanning={unbanning}
                t={t}
                renderExpiry={(ban) => expiryContent(ban, t)}
              />
            </div>

            {/* Fixed-height scroll area, same as the dashboard's process table:
                a full page of bans pushed the paging controls off-screen, so
                getting to page 2 meant scrolling past everything on page 1
                first. The header sticks so columns stay labelled on the way. */}
            <div className="hidden max-h-[26rem] overflow-auto rounded-xl border lg:block [&>div]:rounded-none [&>div]:border-0">
              <DataTable
                columns={columns}
                data={visible}
                emptyMessage={t("banned.noMatches")}
                stickyHeader
                meta={{ canManage, onUnban, unbanning, t }}
              />
            </div>
          </>
        )}

        {pageCount > 1 ? (
          <div className="flex items-center justify-between gap-3">
            <p className="text-xs text-muted-foreground tabular-nums">
              {t("banned.showing", {
                from: page * PAGE_SIZE + 1,
                to: Math.min((page + 1) * PAGE_SIZE, filtered.length),
                total: filtered.length,
              })}
            </p>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={page === 0}
                onClick={() => setPage((p) => p - 1)}
              >
                {t("banned.prev")}
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= pageCount - 1}
                onClick={() => setPage((p) => p + 1)}
              >
                {t("banned.next")}
              </Button>
            </div>
          </div>
        ) : null}
      </CardContent>

      <ConfirmDialog
        open={confirmAll}
        onOpenChange={(open) => !clearing && setConfirmAll(open)}
        icon={TriangleAlert}
        tone="warning"
        title={t("banned.unbanAllTitle")}
        description={t("banned.unbanAllDescription", { count: banned.length })}
        cancelLabel={t("banned.cancel")}
        confirmLabel={t("banned.unbanAll")}
        pending={clearing}
        onConfirm={onUnbanAll}
      />
    </Card>
  );
}

// Minutes up to an hour, then hours — nobody needs "3,412 seconds".
function formatLeft(t, seconds) {
  if (seconds < 60) return t("banned.secondsLeft", { count: seconds });
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return t("banned.minutesLeft", { count: minutes });
  return t("banned.hoursLeft", { count: Math.round(minutes / 60) });
}
