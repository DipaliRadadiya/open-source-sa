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

export default function AppLink({ children, ...props }) {
  return (
    <NextLink {...props}>
      {children}
      <ReportPending />
    </NextLink>
  );
}
