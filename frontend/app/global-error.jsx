"use client";

import { useEffect, useState } from "react";
import { Geist, Geist_Mono } from "next/font/google";
import { RotateCw } from "lucide-react";
import { locales, defaultLocale } from "@/i18n/routing";
import { FailurePanel } from "@/components/ui/failure-panel";
import { Button } from "@/components/ui/button";
import "./globals.css";

// Same fonts as the root layout, which this replaces.
const geistSans = Geist({ variable: "--font-geist-sans", subsets: ["latin"] });
const geistMono = Geist_Mono({ variable: "--font-geist-mono", subsets: ["latin"] });

// Root-layout errors (e.g. a refresh cut off mid-stream). No translation
// provider here, so the `errors` keys are loaded for the reader's locale.
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

  // A reload, not `reset()`: re-rendering the root from the same half-received
  // payload fails the same way.
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
