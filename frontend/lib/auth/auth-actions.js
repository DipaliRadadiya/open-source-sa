import axios from "axios";
import { api } from "@/lib/api/client";

// The CSRF-cookie endpoint lives at the root, NOT under /api, so it needs a
// direct call rather than the /api-prefixed Axios instance.
async function ensureCsrfCookie() {
  await axios.get(`${process.env.NEXT_PUBLIC_API_URL}/sanctum/csrf-cookie`, {
    withCredentials: true,
  });
}

export async function register(values) {
  await ensureCsrfCookie();
  const res = await api.post("/auth/register", values);
  return res.data?.user ?? res.data?.data;
}

export async function login(values) {
  await ensureCsrfCookie();
  const res = await api.post("/auth/login", values);
  return res.data?.user ?? res.data?.data;
}

export async function logout() {
  await api.post("/auth/logout");
}

// Self password change. Backend re-issues a Bearer token, but we're cookie-
// session auth so we ignore it — the session cookie stays valid.
export async function changePassword(values) {
  await api.put("/auth/password", values);
}

// Ends an impersonated session — re-logs the original admin onto the cookie
// session. Backend returns 422 on a normal (non-impersonation) session. After
// success the caller must do a FULL-PAGE navigation so SSR re-reads identity.
export async function stopImpersonating() {
  await api.post("/auth/stop-impersonating");
}
