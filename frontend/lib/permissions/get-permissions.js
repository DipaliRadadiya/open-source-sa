import { cache } from "react";
import { cookies } from "next/headers";

export const getPermissions = cache(async () => {
  const cookieStore = await cookies();

  try {
    const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/permissions`, {
      headers: {
        Accept: "application/json",
        cookie: cookieStore.toString(),
        Referer: process.env.NEXT_PUBLIC_APP_URL,
        Origin: process.env.NEXT_PUBLIC_APP_URL,
      },
      cache: "no-store",
    });

    if (!res.ok) return [];

    const data = await res.json();
    return Array.isArray(data?.permissions) ? data.permissions : [];
  } catch {
    return [];
  }
});
