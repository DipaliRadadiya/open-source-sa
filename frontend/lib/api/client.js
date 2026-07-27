import axios from "axios";

// All backend routes are under /api, so bake the prefix into baseURL —
// endpoint calls stay clean (api.post("/login") -> .../api/login).
export const api = axios.create({
  baseURL: `${process.env.NEXT_PUBLIC_API_URL}/api`,
  withCredentials: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
  // Since Axios 1.6, the XSRF header is only auto-attached to same-origin
  // requests unless this is set. Our API is a different origin (Sanctum SPA),
  // so this is required or every mutation fails CSRF (419).
  withXSRFToken: true,
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && typeof window !== "undefined") {
      window.location.href = "/login";
    }
    return Promise.reject(error);
  }
);
