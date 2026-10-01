import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { toast } from "sonner";
import Link from "@/components/ui/app-link";
import { ShieldOff, Ban, ScrollText } from "lucide-react";
import { unbanIp, unbanAll } from "@/lib/api/fail2ban";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
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
import { useNavTransition } from "@/components/data-table/nav-transition";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { apiMessage } from "@/lib/api/error-message";

// 10: on a phone each ban is a card, and more strands the paging controls.
const PAGE_SIZE = 10;
// Search and paging appear only from this many bans.
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

// No timing reported (older fail2ban) is Unknown, never "permanent"; dated without expiry is Permanent.
function ExpiryCell({ row, table }) {
  return expiryContent(row.original, table.options.meta.t);
}

// Shared with the mobile cards so both widths agree.
function expiryContent(ban, t) {
  const { seconds_left: left, expires_at: expires, banned_at: since } = ban;

  if (typeof left === "number") {
    if (left <= 0) return <span className="text-muted-foreground">{t("banned.expiring")}</span>;
    return <span className="whitespace-nowrap tabular-nums">{formatLeft(t, left)}</span>;
  }

  // No seconds but an expiry: show it verbatim (server clock).
  if (expires) {
    return <span className="whitespace-nowrap text-xs tabular-nums">{expires}</span>;
  }

  // No expiry and no start date means unknown, not permanent.
  if (!since) return <span className="text-muted-foreground">{t("banned.unknownLeft")}</span>;

  return (
    <Badge variant="muted" className="font-normal">
      {t("banned.permanent")}
    </Badge>
  );
}

function ActionsCell({ row, table }) {
  const { canManage, onRequestUnban, unbanning, t } = table.options.meta;
  return (
    <div className="flex justify-end">
      <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
        <Button
          variant="destructive"
          size="sm"
          disabled={!canManage || unbanning === row.original.ip}
          onClick={() => onRequestUnban(row.original)}
        >
          <ShieldOff className="size-4" />
          {t("banned.unban")}
        </Button>
      </ReasonTooltip>
    </div>
  );
}

export function BannedCard({ banned, jails, canManage, logHref, yourIp = null, serverIp = null }) {
  const t = useTranslations("fail2ban");
  // One shared transition dims the table during every post-write re-read.
  const nav = useNavTransition();
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [localPending, startLocal] = useTransition();
  const refresh = nav ? nav.refresh : () => startLocal(() => router.refresh());
  const pending = nav ? nav.isPending : localPending;
  const [unbanning, setUnbanning] = useState(null);
  const [unbanConfirm, setUnbanConfirm] = useState(null);
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

  // Clamped during render, not in an effect, to avoid a flash of an empty page.
  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const page = Math.min(rawPage, pageCount - 1);
  const visible = filtered.slice(page * PAGE_SIZE, (page + 1) * PAGE_SIZE);
  const setPage = setRawPage;

  async function onUnban(ban) {
    setUnbanning(ban.ip);
    try {
      await unbanIp(ban.ip, ban.jail);
      await refreshAndWait();
      toast.success(t("banned.unbanned", { ip: ban.ip }));
    } catch (error) {
      // 404 = not banned anywhere: the list is stale, so reload it.
      if (error.response?.status === 404) {
        toast.info(t("banned.alreadyGone"));
        refresh();
        return;
      }
      toast.error(apiMessage(error, t("banned.failed")));
    } finally {
      setUnbanning(null);
      setUnbanConfirm(null);
    }
  }

  async function onUnbanAll() {
    setClearing(true);
    try {
      const { data } = await unbanAll();
      await refreshAndWait();
      toast.success(t("banned.unbannedAll", { count: data?.unbanned?.ips?.length ?? 0 }));
      setConfirmAll(false);
    } catch (error) {
      if (error.response?.status === 404) {
        toast.info(t("banned.noneToClear"));
        setConfirmAll(false);
        refresh();
        return;
      }
      toast.error(apiMessage(error, t("banned.failed")));
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
          <RefreshButton />
          {/* The log shows what triggered a ban. */}
          {logHref ? (
            <Button variant="ghost" size="sm" asChild>
              <Link href={logHref}>
                <ScrollText className="size-4" />
                {t("banned.seeLog")}
              </Link>
            </Button>
          ) : null}
          <BanIpDialog jails={jails} canManage={canManage} yourIp={yourIp} serverIp={serverIp} />
          {/* Kept visible for a fast recovery from banning yourself. */}
          {banned.length > 0 ? (
            <ReasonTooltip reason={canManage ? null : t("disabled.noPermission")}>
              <Button
                variant="destructive"
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
        {needsTools ? (
          <div className="flex flex-col gap-2 sm:flex-row">
            <LocalSearchInput
              value={query}
              onChange={setQuery}
              placeholder={t("banned.search")}
              className="sm:max-w-64"
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
            {/* Cards below lg, table from lg. The cards dim themselves here
                since they are not a DataTable. */}
            <div className={cn("lg:hidden", pending && "pointer-events-none opacity-60")}>
              <BannedCards
                data={visible}
                canManage={canManage}
                onRequestUnban={setUnbanConfirm}
                unbanning={unbanning}
                t={t}
                renderExpiry={(ban) => expiryContent(ban, t)}
              />
            </div>

            {/* Fixed-height scroll area keeps paging controls on screen; the
                header sticks. */}
            <div className="hidden max-h-[26rem] overflow-auto rounded-xl border lg:block [&>div]:rounded-none [&>div]:border-0">
              <DataTable
                columns={columns}
                data={visible}
                emptyMessage={t("banned.noMatches")}
                stickyHeader
                meta={{ canManage, onRequestUnban: setUnbanConfirm, unbanning, t }}
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
        icon={ShieldOff}
        tone="warning"
        confirmVariant="destructive"
        title={t("banned.unbanAllTitle", { count: banned.length })}
        description={t("banned.unbanAllDescription")}
        cancelLabel={t("banned.cancel")}
        confirmLabel={t("banned.unbanAll")}
        pending={clearing}
        onConfirm={onUnbanAll}
      >
        {/* Capped and scrollable so hundreds of bans never push the confirm
            button off screen. */}
        <ul className="max-h-56 divide-y overflow-y-auto rounded-lg border">
          {banned.map((ban) => (
            <li
              key={`${ban.jail}-${ban.ip}`}
              className="flex items-center justify-between gap-3 px-3 py-2"
            >
              <span className="truncate font-mono text-sm">{ban.ip}</span>
              <span className="shrink-0 text-xs text-muted-foreground">{ban.jail}</span>
            </li>
          ))}
        </ul>
      </ConfirmDialog>

      <ConfirmDialog
        open={!!unbanConfirm}
        onOpenChange={(open) => !unbanning && !open && setUnbanConfirm(null)}
        icon={ShieldOff}
        tone="warning"
        confirmVariant="destructive"
        title={t("banned.unbanIpTitle", { ip: unbanConfirm?.ip })}
        description={t("banned.unbanIpDescription", { jail: unbanConfirm?.jail })}
        cancelLabel={t("banned.cancel")}
        confirmLabel={t("banned.unban")}
        pending={!!unbanning}
        onConfirm={() => unbanConfirm && onUnban(unbanConfirm)}
      />
    </Card>
  );
}

// Seconds, then minutes up to an hour, then hours.
function formatLeft(t, seconds) {
  if (seconds < 60) return t("banned.secondsLeft", { count: seconds });
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return t("banned.minutesLeft", { count: minutes });
  return t("banned.hoursLeft", { count: Math.round(minutes / 60) });
}
