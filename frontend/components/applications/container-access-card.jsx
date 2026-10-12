"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Copy, SquareTerminal, UserPlus } from "lucide-react";
import Link from "@/components/ui/app-link";
import { getContainerAccess } from "@/lib/api/docker";
import { containerAccessSchema } from "@/lib/schemas/docker";
import {
  buildAccessCommand,
  defaultContainer,
  readySshUsers,
  showsContainerAccess,
} from "@/lib/applications/container-access";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * Two steps to a shell inside this app's container: have a system user that can
 * SSH and sudo, then paste one line that logs in and runs `docker exec`.
 *
 * An extra, not a status: a failed read hides the card (with a console warning)
 * rather than putting an error on the dashboard.
 */
export function ContainerAccessCard({ application, canSeeSystemUsers = false, className }) {
  const t = useTranslations("applications.containerAccess");
  const tc = useTranslations("common");
  const enabled = showsContainerAccess(application);
  const [access, setAccess] = useState(null);
  const [failed, setFailed] = useState(false);
  const [user, setUser] = useState("");
  const [container, setContainer] = useState("");

  useEffect(() => {
    if (!enabled) return undefined;
    let cancelled = false;

    getContainerAccess(application.id)
      .then(({ data }) => {
        const parsed = containerAccessSchema.safeParse(data?.data ?? data);
        if (!parsed.success) throw parsed.error;
        if (!cancelled) setAccess(parsed.data);
      })
      .catch((error) => {
        console.warn("Command-line access is unavailable for this application.", error);
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
    };
  }, [application.id, enabled]);

  if (!enabled || failed) return null;

  const users = readySshUsers(access?.ssh_users);
  const containers = access?.containers ?? [];
  const selected =
    containers.find((item) => item.name === container) ?? defaultContainer(containers);
  const command = access
    ? buildAccessCommand({
        user,
        host: access.host,
        port: access.ssh_port,
        container: selected?.name,
      })
    : "";

  async function copy() {
    try {
      await navigator.clipboard.writeText(command);
      toast.success(t("copied"));
    } catch {
      toast.error(tc("copyFailed"));
    }
  }

  return (
    <Card className={className} aria-labelledby="container-access-heading">
      <CardHeader>
        <CardTitle
          id="container-access-heading"
          as="h2"
          className="flex items-center gap-2 text-lg font-semibold"
        >
          <SquareTerminal className="size-4 text-primary" />
          {t("title")}
        </CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent>
        {!access ? (
          <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]" aria-busy="true" aria-label={t("loading")}>
            <div className="space-y-2">
              <Skeleton className="h-4 w-40" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-8 w-36" />
            </div>
            <div className="space-y-2">
              <Skeleton className="h-4 w-32" />
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-4 w-3/4" />
            </div>
          </div>
        ) : (
          <ol className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <Step n={1} title={t("step1.title")}>
              <p className="text-sm text-muted-foreground">{t("step1.body")}</p>
              {canSeeSystemUsers ? (
                <Button asChild variant="outline" size="sm">
                  <Link href="/system-users" prefetch={false}>
                    <UserPlus className="size-4" />
                    {t("step1.open")}
                  </Link>
                </Button>
              ) : null}
              {users.length > 0 ? (
                <div className="space-y-1.5">
                  <Label htmlFor="container-access-user">{t("step1.pick")}</Label>
                  <Select value={user} onValueChange={setUser}>
                    <SelectTrigger id="container-access-user" className="w-full sm:w-64">
                      <SelectValue placeholder={t("step1.pickPlaceholder")} />
                    </SelectTrigger>
                    <SelectContent>
                      {users.map((item) => (
                        <SelectItem key={item.username} value={item.username}>
                          {item.username}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ) : null}
            </Step>

            <Step n={2} title={t("step2.title")}>
              {containers.length > 1 ? (
                <div className="space-y-1.5">
                  <Label htmlFor="container-access-container">{t("step2.container")}</Label>
                  <Select value={selected?.name ?? ""} onValueChange={setContainer}>
                    <SelectTrigger id="container-access-container" className="w-full sm:w-64">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {containers.map((item) => (
                        <SelectItem key={item.name} value={item.name}>
                          {item.service}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ) : null}
              <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                {/* Wraps instead of scrolling so the whole line is visible at 390px. */}
                <pre
                  data-testid="container-access-command"
                  className="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs leading-5 wrap-anywhere whitespace-pre-wrap"
                >
                  {command}
                </pre>
                <Button type="button" variant="outline" size="sm" onClick={copy} className="shrink-0 self-start">
                  <Copy className="size-4" />
                  {tc("copy")}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                {t.rich("step2.note", {
                  code: (chunks) => <code className="font-mono">{chunks}</code>,
                })}
              </p>
            </Step>
          </ol>
        )}
      </CardContent>
    </Card>
  );
}

function Step({ n, title, children }) {
  return (
    <li className="flex gap-2.5">
      <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border bg-muted text-xs font-semibold tabular-nums text-muted-foreground">
        {n}
      </span>
      <div className="min-w-0 flex-1 space-y-3">
        <p className="text-sm font-medium leading-6">{title}</p>
        {children}
      </div>
    </li>
  );
}
