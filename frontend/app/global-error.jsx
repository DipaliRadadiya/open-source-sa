"use client";

import { useEffect, useState } from "react";
import { Geist, Geist_Mono } from "next/font/google";
import { RotateCw } from "lucide-react";
import { locales, defaultLocale } from "@/i18n/routing";
import { FailurePanel } from "@/components/ui/failure-panel";
import { Button } from "@/components/ui/button";
import "./globals.css";

// The same faces as the root layout, which this replaces.
const geistSans = Geist({ variable: "--font-geist-sans", subsets: ["latin"] });
const geistMono = Geist_Mono({ variable: "--font-geist-mono", subsets: ["latin"] });

/*
 * The last boundary: an error in the root layout itself. The one that reaches
 * it in practice is a refresh whose response is cut off mid-stream ("Connection
 * closed."), which a lossy link produces. Without this file Next showed its own
 * unstyled English page.
 *
 * It replaces the root layout, so there is no translation provider here. The
 * words are the same `errors` keys app/error.jsx uses, loaded for the reader's
 * locale; until they arrive (or if the connection that just failed cannot
 * fetch them) the Reload button still works on its icon.
 */
function readerLocale() {
  const cookie = document.cookie.match(/(?:^|;\s*)NEXT_LOCALE=([^;]+)/)?.[1];
  if (locales.includes(cookie)) return cookie;
  const browser = navigator.language?.slice(0, 2);
  return locales.includes(browser) ? browser : defaultLocale;
}

function prefersDark() {
  if (typeof window === "undefined") return false;
  const saved = window.localStorage.getItem("theme");
  if (saved === "dark") return true;
  if (saved === "light") return false;
  return window.matchMedia("(prefers-color-scheme: dark)").matches;
}

export default function GlobalError({ error }) {
  const [copy, setCopy] = useState(null);
  const [locale, setLocale] = useState(defaultLocale);
  const [dark] = useState(prefersDark);

  useEffect(() => {
    let live = true;
    const chosen = readerLocale();
    import(`../messages/${chosen}.json`)
      .then((messages) => {
        if (!live) return;
        setLocale(chosen);
        setCopy(messages.default.errors);
      })
      .catch(() => {});
    return () => {
      live = false;
    };
  }, []);

  // A reload, not `reset()`: the tree that failed is the root, and re-rendering
  // it from the same half-received payload fails the same way.
  const reload = (
    <Button variant="outline" onClick={() => window.location.reload()} aria-label={copy?.retry}>
      <RotateCw className="size-4" />
      {copy?.retry}
    </Button>
  );

  return (
    <html lang={locale} className={`${geistSans.variable} ${geistMono.variable}${dark ? " dark" : ""}`}>
      <body className="bg-background text-foreground antialiased">
        {copy ? (
          <FailurePanel
            centered
            className="w-full max-w-md"
            title={copy.title}
            description={copy.description}
            detail={error?.digest}
            action={reload}
          />
        ) : (
          <div className="flex min-h-svh items-center justify-center">{reload}</div>
        )}
      </body>
    </html>
  );
}
