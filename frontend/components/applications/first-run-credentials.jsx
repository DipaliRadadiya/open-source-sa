"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Eye, EyeOff, KeyRound, Loader2 } from "lucide-react";
import {
  acknowledgeContainerSecrets,
  getContainerSecrets,
} from "@/lib/api/docker";
import { apiMessage } from "@/lib/api/error-message";
import { Card, CardAction, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { CopyButton } from "@/components/ui/copy-button";
import { Caution } from "@/components/ui/caution";
import { FACT_CELL, FACT_FRAME, FACT_GRID, FACT_LABEL } from "@/lib/theme/facts";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

const SECRET_LABELS = new Set(["password", "token"]);
const SECRET_KEY = /PASS|SECRET|TOKEN|KEY/i;

export function FirstRunCredentials({ application, className }) {
  const t = useTranslations("applications.container.firstRun");
  const router = useRouter();
  const [secrets, setSecrets] = useState(null);
  const [credentials, setCredentials] = useState(null);
  const [error, setError] = useState(null);
  const [saving, setSaving] = useState(false);
  const [dismissed, setDismissed] = useState(false);

  const keys = application.container_secret_keys ?? [];
  const LABELS = {
    username: t("labels.username"),
    password: t("labels.password"),
    email: t("labels.email"),
    url: t("labels.url"),
    token: t("labels.token"),
  };
  const rows = Array.isArray(credentials)
    ? credentials.map((item, index) => ({ key: `${item.label}-${index}`, label: LABELS[item.label], value: item.value, curated: true, secret: typeof item.secret === "boolean" ? item.secret : SECRET_LABELS.has(item.label) }))
    : keys.map((key) => ({ key, label: key, value: secrets?.[key], curated: false, secret: SECRET_KEY.test(key) }));

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const { data } = await getContainerSecrets(application.id);
        if (!cancelled) {
          setSecrets(data?.secrets ?? {});
          setCredentials(data?.credentials ?? null);
        }
      } catch (requestError) {
        if (!cancelled) setError(apiMessage(requestError, t("failed")));
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, [application.id, t]);

  async function acknowledge() {
    setSaving(true);
    try {
      await acknowledgeContainerSecrets(application.id);
      setDismissed(true);
      router.refresh();
    } catch (requestError) {
      toast.error(apiMessage(requestError, t("acknowledgeFailed")));
    } finally {
      setSaving(false);
    }
  }

  if (keys.length === 0 || dismissed) return null;

  return (
    <Card className={className}>
      <CardHeader className="items-center border-b">
        <CardTitle icon={KeyRound} as="h2">{t("title")}</CardTitle>
        <CardAction className="row-span-1 self-center">
          <Button
            size="sm"
            onClick={acknowledge}
            disabled={saving || !secrets}
            disabledReason={!secrets ? t("valuesNotShown") : null}
          >
            {saving ? <Loader2 className="size-3.5 animate-spin" /> : null}
            {t("acknowledge")}
          </Button>
        </CardAction>
      </CardHeader>
      <CardContent className="space-y-3">
        <Caution size="md" icon={KeyRound}>
          <p>{t("body")}</p>
        </Caution>

        {error ? (
          <Caution size="md" tone="destructive">
            <p>{error}</p>
          </Caution>
        ) : (
          <div className={FACT_FRAME}>
            <ul className={`${FACT_GRID} grid-cols-1 sm:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]`}>
              {rows.map((row) => (
                <li key={row.key} className={`${FACT_CELL} space-y-1`}>
                  <span className={row.curated ? `block ${FACT_LABEL}` : `block truncate ${FACT_LABEL}`}>
                    {row.label}
                  </span>
                  {secrets ? (
                    <span className="flex min-w-0 items-center gap-1.5">
                      <SecretValue value={row.value} secret={row.secret} />
                    </span>
                  ) : (
                    <Loader2
                      className="size-3.5 shrink-0 animate-spin text-muted-foreground"
                      aria-label={t("loading")}
                    />
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}

        <p className="border-t pt-3 text-xs text-muted-foreground">{t("laterHint")}</p>
      </CardContent>
    </Card>
  );
}

function SecretValue({ value, secret }) {
  const t = useTranslations("common");
  const [shown, setShown] = useState(false);
  const masked = secret && !shown;

  return (
    <>
      <code className="min-w-0 break-all font-mono text-[13px] font-medium">
        {masked ? "••••••••••••" : value}
      </code>
      {secret ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              onClick={() => setShown((s) => !s)}
              aria-label={shown ? t("hidePassword") : t("showPassword")}
              aria-pressed={shown}
              className="flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
              {shown ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
            </button>
          </TooltipTrigger>
          <TooltipContent>{shown ? t("hidePassword") : t("showPassword")}</TooltipContent>
        </Tooltip>
      ) : null}
      <CopyButton value={value ?? ""} />
    </>
  );
}
