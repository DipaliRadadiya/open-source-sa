"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, TriangleAlert } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { completeDriveConnect } from "@/lib/api/storage";
import { apiMessage } from "@/lib/api/error-message";

const STORAGE_PAGE = "/integrations/storage";

export function CallbackCard({ status, message }) {
  const t = useTranslations("storage.oauth");

  const view = {
    working: {
      icon: Loader2,
      spin: true,
      tone: "bg-muted text-muted-foreground",
      title: t("callbackTitle"),
      body: t("finishing"),
    },
    connected: {
      icon: CheckCircle2,
      tone: "bg-success/10 text-success",
      title: t("connected"),
      body: t("scopeNote"),
    },
    failed: {
      icon: TriangleAlert,
      tone: "bg-destructive/10 text-destructive",
      title: t("callbackFailed"),
      body: message,
    },
  }[status];

  const Icon = view.icon;

  return (
    <div className="w-full max-w-md rounded-2xl border bg-card p-6 text-center shadow-e1 ring-1 ring-foreground/[0.07]">
      <span
        className={cn(
          "mx-auto flex size-12 items-center justify-center rounded-full",
          view.tone,
        )}
      >
        <Icon className={cn("size-6", view.spin && "animate-spin")} aria-hidden />
      </span>

      <h1 className="mt-4 text-lg font-semibold tracking-tight">{view.title}</h1>
      {view.body ? (
        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{view.body}</p>
      ) : null}

      {status !== "working" ? (
        <Button
          asChild
          variant={status === "connected" ? "default" : "outline"}
          className="mt-6 w-full"
        >
          <a href={STORAGE_PAGE}>{t("backToStorage")}</a>
        </Button>
      ) : null}
    </div>
  );
}

// The code is single-use and strict mode runs effects twice, so the ref guards a second exchange.
export function GoogleDriveCallback({ code, state, deniedError }) {
  const t = useTranslations("storage.oauth");
  const router = useRouter();
  const [result, setResult] = useState(null);
  const started = useRef(false);

  // Derived during render; setState in the effect is a cascading render.
  const blocked = deniedError
    ? deniedError === "access_denied"
      ? t("denied")
      : t("token_failed")
    : !code || !state
      ? t("state_invalid")
      : null;

  useEffect(() => {
    if (blocked || started.current) return;
    started.current = true;

    completeDriveConnect({ code, state })
      .then(({ data }) => {
        if (data.oauth.status === "connected") {
          router.refresh();
          setResult({ status: "connected", message: null });
          return;
        }

        setResult({ status: "failed", message: data.oauth.message ?? t("token_failed") });
      })
      .catch((e) => {
        setResult({ status: "failed", message: apiMessage(e, t("token_failed")) });
      });
  }, [blocked, code, state, router, t]);

  const status = blocked ? "failed" : (result?.status ?? "working");
  const message = blocked ?? result?.message ?? null;

  return <CallbackCard status={status} message={message} />;
}
