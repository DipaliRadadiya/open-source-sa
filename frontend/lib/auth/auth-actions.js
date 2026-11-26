import axios from "axios";
import { api } from "@/lib/api/client";

// The CSRF cookie endpoint is not under /api.
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

export async function updateProfile(values) {
  const res = await api.put("/auth/profile", values);
  return res.data?.user;
}

export async function changePassword(values) {
  await api.put("/auth/password", values);
}

// Caller must do a full page load afterwards so SSR reads the new session.
export async function stopImpersonating() {
  await api.post("/auth/stop-impersonating");
}
