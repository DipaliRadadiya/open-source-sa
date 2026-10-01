import { cache } from "react";
import { DEFAULT_PASSWORD_POLICY } from "@/lib/auth/password-rules";

const DEFAULT_BASIC_INFO = {
  registration_open: false,
  app_version: null,
  locales_available: [],
  cookie_auth_enabled: true,
  // Fallback only when the endpoint cannot be read; the server publishes the
  // real policy.
  password_policy: DEFAULT_PASSWORD_POLICY,
};

export const getBasicInfo = cache(async () => {
  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/basic-info`, {
      cache: "no-store",
    });

    if (!res.ok) return DEFAULT_BASIC_INFO;

    const data = await res.json();
    const info = data?.basic_info;
    if (!info) return DEFAULT_BASIC_INFO;

    // Merged: an older backend sends no password_policy.
    return { ...DEFAULT_BASIC_INFO, ...info, password_policy: info.password_policy ?? DEFAULT_PASSWORD_POLICY };
  } catch {
    return DEFAULT_BASIC_INFO;
  }
});
