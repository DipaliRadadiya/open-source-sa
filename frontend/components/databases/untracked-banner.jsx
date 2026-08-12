"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, PackageSearch } from "lucide-react";
import { adoptDatabases } from "@/lib/api/databases";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Databases that are on the server but not in the panel.
 *
 * Without this the list quietly misrepresents the box: a server migrated in
 * with six databases shows an empty page and an invitation to create one. The
 * banner is deliberately not an error — nothing is wrong, there is just more
 * here than the panel knows about.
 *
 * Adopting never touches the data; it only starts tracking what already exists.
 */
export function UntrackedBanner({ untracked = [], canManage }) {
  const t = useTranslations("databases");
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);
  // Everything ticked to begin with — the usual answer is "all of them", and
  // the list is still there to untick.
  const [chosen, setChosen] = useState(() => untracked.map((item) => item.name));

  if (untracked.length === 0) return null;

  const allChosen = chosen.length === untracked.length;

  function toggle(name) {
    setChosen((current) =>
      current.includes(name)
        ? current.filter((item) => item !== name)
        : [...current, name],
    );
  }

  async function onConfirm() {
    setPending(true);
    try {
      // Adopt is scoped per engine, so one request per engine represented in
      // the selection.
      const byEngine = new Map();
      for (const item of untracked) {
        if (!chosen.includes(item.name)) continue;
        byEngine.set(item.engine, [...(byEngine.get(item.engine) ?? []), item.name]);
      }

      for (const [engine, names] of byEngine) {
        await adoptDatabases(engine, names);
      }

      toast.success(t("adopt.done", { count: chosen.length }));
      setOpen(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("adopt.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <>
      <div className="flex flex-col gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div className="flex items-start gap-2.5">
          <PackageSearch className="mt-0.5 size-4 shrink-0 text-warning" />
          <div className="space-y-0.5">
            <p className="text-sm font-medium">
              {t("adopt.title", { count: untracked.length })}
            </p>
            <p className="text-xs text-muted-foreground">
              {t("adopt.description")}
            </p>
          </div>
        </div>

        {canManage ? (
          <Button
            variant="outline"
            size="sm"
            className="shrink-0"
            onClick={() => setOpen(true)}
          >
            {t("adopt.action")}
          </Button>
        ) : null}
      </div>

      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        // Wider than a yes/no confirmation because this one asks you to read a
        // list, and a generated name like `wordpress_139_59_88_213_nip_io_4fguku`
        // does not fit 384px. `!` is required — see restore-dialog.jsx.
        className="w-full sm:!max-w-lg"
        icon={PackageSearch}
        title={t("adopt.confirmTitle")}
        description={t("adopt.confirmDescription")}
        cancelLabel={t("cancel")}
        confirmLabel={
          pending ? (
            <>
              <Loader2 className="size-4 animate-spin" />
              {t("adopt.adopting")}
            </>
          ) : (
            t("adopt.submit")
          )
        }
        confirmDisabled={chosen.length === 0}
        pending={pending}
        onConfirm={onConfirm}
      >
        <div className="space-y-2">
          {/* Everything starts ticked, so the count is the only thing that says
              what you are about to take — and the toggle is how you get back to
              "all of them" after unticking one by mistake. */}
          <div className="flex items-center justify-between gap-3">
            <p className="text-xs text-muted-foreground tabular-nums">
              {t("adopt.selectedCount", {
                selected: chosen.length,
                total: untracked.length,
              })}
            </p>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 px-2 text-xs"
              disabled={pending}
              onClick={() =>
                setChosen(allChosen ? [] : untracked.map((item) => item.name))
              }
            >
              {allChosen ? t("adopt.clearAll") : t("adopt.selectAll")}
            </Button>
          </div>

          {/* Bounded with its own scroll: a server migrated in can carry fifty
              of these, and a dialog that grows with the list pushes Adopt off
              the bottom of the screen. */}
          <div className="max-h-64 space-y-1 overflow-y-auto rounded-lg border p-1">
            {untracked.map((item) => (
              <label
                key={`${item.engine}-${item.name}`}
                className="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 transition-colors hover:bg-muted/50"
              >
                <Checkbox
                  className="shrink-0"
                  checked={chosen.includes(item.name)}
                  disabled={pending}
                  onCheckedChange={() => toggle(item.name)}
                />
                {/* Wrapped, never truncated. These names are generated and
                    differ only in the last few characters, so an ellipsis at
                    the end turns `..._9ulk1c` and `..._irlezq` into the same
                    row — on a phone you would be ticking boxes blind. */}
                <span className="min-w-0 flex-1 font-mono text-sm break-all">
                  {item.name}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                  {t(`engines.${item.engine}`)}
                </span>
              </label>
            ))}
          </div>
        </div>
      </ConfirmDialog>
    </>
  );
}
