"use client";

import { useEffect } from "react";
import { usePathname, useSearchParams } from "next/navigation";
import { rememberPath } from "@/lib/auth/last-path";

/**
 * Records the screen you are on, so signing back in returns you to it.
 *
 * This exists because the server cannot answer the question. A page here sees
 * only `host`, `user-agent`, `accept` and the `x-forwarded-*` set — Next sets
 * no header naming the route being rendered, and there is no proxy to add one
 * (`proxy.js` went when locale routing moved to a cookie). So the redirect to
 * /login genuinely does not know where it came from, and the only side that
 * does is the browser.
 *
 * `sessionStorage`, not `localStorage`, on purpose: it is scoped to the tab.
 * Two tabs whose sessions expire should each come back to their own screen,
 * and a shared key would make whichever signed in last decide for both. It
 * also dies with the tab, which is the right lifetime for "where I was".
 *
 * Mounted inside the authenticated shells only, so a signed-out screen never
 * records itself — and `safeNext` refuses those paths a second time anyway.
 */
export function RememberPath() {
  const pathname = usePathname();
  const searchParams = useSearchParams();

  useEffect(() => {
    // The query is part of where you were: a filtered list is a different
    // screen from the bare route, and returning to the bare one silently
    // throws away what the reader had set up.
    const query = searchParams.toString();
    rememberPath(query ? `${pathname}?${query}` : pathname);
  }, [pathname, searchParams]);

  return null;
}
