"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowLeft, ArrowRight, LayoutDashboard } from "lucide-react";
import { Button } from "@/components/ui/button";
import { NavIcon } from "@/components/nav-icon";

/**
 * Shared 404 body. Two entry points render it: the root `not-found` (an unknown
 * URL, which Next always resolves outside the panel layout, so there's no
 * sidebar to lean on) and the panel's own, where the shell survives.
 *
 * Two columns: the message on the left, somewhere to actually go on the right.
 * A dead end's job is to end — the list is the difference between a page that
 * reports a problem and one that resolves it. `links` is already permission-
 * filtered by the caller, so nothing here offers a door the user can't open.
 */
export function NotFoundContent({ links = [] }) {
  const t = useTranslations("errors.notFound");
  const router = useRouter();

  return (
    <div className="grid w-full max-w-5xl gap-12 lg:grid-cols-2 lg:gap-16">
      <div>
        {/* Big, plain, foreground. Every attempt to decorate this number —
            gradient, watermark, badge — made it look like a defect. At this
            size it just reads as a title. */}
        <p aria-hidden="true" className="text-7xl font-bold leading-none tracking-tight">
          404
        </p>

        <h1 className="mt-6 text-2xl font-semibold tracking-tight">{t("title")}</h1>
        <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
          {t("description")}
        </p>

        <div className="mt-8 flex flex-wrap items-center gap-2">
          <Button asChild>
            <Link href="/dashboard">
              <LayoutDashboard className="size-4" />
              {t("dashboard")}
            </Link>
          </Button>
          {/* Back is the more likely intent — a mistyped URL is usually one step
              from somewhere real — but it can't be the primary action, because
              history isn't guaranteed to hold anything. */}
          <Button variant="ghost" onClick={() => router.back()}>
            <ArrowLeft className="size-4" />
            {t("back")}
          </Button>
        </div>
      </div>

      {/* Omitted entirely when signed out or unpermissioned: an empty heading
          over nothing is worse than no column at all. */}
      {links.length > 0 ? (
        <nav aria-label={t("linksLabel")}>
          <h2 className="text-sm font-semibold">{t("linksLabel")}</h2>
          <ul className="mt-2 divide-y divide-border/60">
            {links.map((item) => (
              <li key={item.url}>
                <Link
                  href={item.url}
                  className="group flex items-center gap-3 rounded-md py-3 transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                  <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground transition-colors group-hover:bg-primary/10 group-hover:text-primary">
                    <NavIcon name={item.icon} />
                  </span>
                  <span className="min-w-0 flex-1 truncate text-sm font-medium">
                    {item.title}
                  </span>
                  <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-primary motion-reduce:transition-none" />
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}
    </div>
  );
}
