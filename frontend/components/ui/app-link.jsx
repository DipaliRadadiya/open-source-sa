"use client";

import { useEffect, useRef } from "react";
import NextLink, { useLinkStatus } from "next/link";
import {
  beginNavigation,
  holdUntilPageChanges,
} from "@/lib/browser/navigation-pending";

function ReportPending() {
  const { pending } = useLinkStatus();
  const mounted = useRef(true);

  // Declared first so its cleanup runs first on unmount: the effect below can
  // then tell "the page arrived" from "this link went away while waiting".
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  useEffect(() => {
    if (!pending) return;
    const release = beginNavigation();
    return () => (mounted.current ? release() : holdUntilPageChanges(release));
  }, [pending]);

  return null;
}

/**
 * `next/link` that tells the top bar when it is waiting.
 *
 * Panel routes are dynamic and most links are `prefetch={false}` (a prefetch
 * is a full server render against the API's rate budget), so a click waits for
 * the server before `loading.jsx` can show. On a slow connection that was a
 * second or more of nothing happening. `useLinkStatus` is only readable inside
 * a Link, so every Link carries this reporter.
 */
export default function AppLink({ children, ...props }) {
  return (
    <NextLink {...props}>
      {children}
      <ReportPending />
    </NextLink>
  );
}
