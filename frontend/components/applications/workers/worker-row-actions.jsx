import Link from "next/link";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { MoreHorizontal, Pencil, ScrollText, SquareArrowOutUpRight, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { EditWorkerDialog } from "@/components/applications/workers/edit-worker-dialog";
import { DeleteWorkerDialog } from "@/components/applications/workers/delete-worker-dialog";

export function WorkerRowActions({ worker, appId, presets, workers = [], canManage }) {
  const t = useTranslations("applications.workers");
  const [editOpen, setEditOpen] = useState(false);
  const [delOpen, setDelOpen] = useState(false);

  return (
    <div className="text-right">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8">
            <MoreHorizontal className="size-4" />
            <span className="sr-only">{t("actions.label")}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-44" onCloseAutoFocus={(e) => e.preventDefault()}>
          {worker.log_identifier ? (
            <DropdownMenuItem asChild>
              {/* New tab, not in-place — this leaves the server-wide Logs
                  page, not another spot in this app, so navigating there
                  would lose the Workers list and the Application sidebar. */}
              <Link
                href={`/logs?source=${encodeURIComponent(worker.log_identifier)}`}
                target="_blank"
                rel="noreferrer"
              >
                <ScrollText className="size-4" />
                {t("actions.viewLogs")}
                <SquareArrowOutUpRight className="ml-auto size-3.5 text-muted-foreground" />
              </Link>
            </DropdownMenuItem>
          ) : null}
          {canManage ? (
            <>
              {worker.log_identifier ? <DropdownMenuSeparator /> : null}
              <DropdownMenuItem onSelect={() => setEditOpen(true)}>
                <Pencil className="size-4" />
                {t("actions.edit")}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem variant="destructive" onSelect={() => setDelOpen(true)}>
                <Trash2 className="size-4" />
                {t("actions.delete")}
              </DropdownMenuItem>
            </>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      {editOpen ? (
        <EditWorkerDialog
          worker={worker}
          appId={appId}
          presets={presets}
          workers={workers}
          open={editOpen}
          onOpenChange={setEditOpen}
        />
      ) : null}
      {delOpen ? (
        <DeleteWorkerDialog
          worker={worker}
          appId={appId}
          open={delOpen}
          onOpenChange={setDelOpen}
        />
      ) : null}
    </div>
  );
}
