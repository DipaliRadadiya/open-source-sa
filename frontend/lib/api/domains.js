import { api } from "@/lib/api/client";

// A domain hostname goes in the URL path; encode it so a value with dots (or an
// unexpected character the backend will still 422) can't break the request.
const seg = (domain) => encodeURIComponent(domain);

// ---- Domains ----------------------------------------------------------------

export async function addDomain(appId, body) {
  const res = await api.post(`/applications/${appId}/domains`, body);
  return res.data?.domain;
}

// Re-checks DNS for one name. Returns the refreshed domain.
export async function verifyDomain(appId, domain) {
  const res = await api.post(`/applications/${appId}/domains/${seg(domain)}/verify`);
  return res.data?.domain;
}

// Promotes a name to canonical; the old primary stays attached as an alias.
export async function makePrimaryDomain(appId, domain) {
  const res = await api.post(`/applications/${appId}/domains/${seg(domain)}/primary`);
  return res.data?.domains;
}

export async function deleteDomain(appId, domain) {
  await api.delete(`/applications/${appId}/domains/${seg(domain)}`);
}

// ---- Certificate ------------------------------------------------------------

// letsencrypt/self_signed → 202 pending (poll GET); custom → 201 active.
export async function issueCertificate(appId, body) {
  const res = await api.post(`/applications/${appId}/certificate`, body);
  return res.data?.certificate;
}

// Poll target while a certificate is pending/issuing.
export async function fetchCertificate(appId) {
  const res = await api.get(`/applications/${appId}/certificate`);
  return res.data?.certificate ?? null;
}

// Refused (422) unless a certificate is active — redirecting to HTTPS with
// nothing on 443 takes the site offline.
export async function setForceHttps(appId, forceHttps) {
  const res = await api.put(`/applications/${appId}/certificate/force-https`, {
    force_https: forceHttps,
  });
  return res.data?.certificate;
}

export async function deleteCertificate(appId) {
  await api.delete(`/applications/${appId}/certificate`);
}
