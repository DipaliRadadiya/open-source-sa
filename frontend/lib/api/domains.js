import { api } from "@/lib/api/client";

// Encode it, the hostname goes in the URL path.
const seg = (domain) => encodeURIComponent(domain);

export async function addDomain(appId, body) {
  const res = await api.post(`/applications/${appId}/domains`, body);
  return res.data?.domain;
}

export async function verifyDomain(appId, domain) {
  const res = await api.post(`/applications/${appId}/domains/${seg(domain)}/verify`);
  return res.data?.domain;
}

export async function makePrimaryDomain(appId, domain) {
  const res = await api.post(`/applications/${appId}/domains/${seg(domain)}/primary`);
  return res.data?.domains;
}

// Renaming a domain breaks certbot renewal, so rename is delete + add.
export async function updateDomain(appId, domain, body) {
  const res = await api.put(`/applications/${appId}/domains/${seg(domain)}`, body);
  return res.data?.domain;
}

export async function deleteDomain(appId, domain) {
  await api.delete(`/applications/${appId}/domains/${seg(domain)}`);
}

export async function issueCertificate(appId, body) {
  const res = await api.post(`/applications/${appId}/certificate`, body);
  return res.data?.certificate;
}

export async function startCertificateDryRun(appId) {
  const res = await api.post(`/applications/${appId}/certificate/dry-run`);
  return res.data?.dry_run ?? null;
}

export async function fetchCertificateDryRun(appId) {
  const res = await api.get(`/applications/${appId}/certificate/dry-run`);
  return res.data?.dry_run ?? null;
}

export async function fetchCertificate(appId) {
  const res = await api.get(`/applications/${appId}/certificate`);
  return res.data?.certificate ?? null;
}

export async function setForceHttps(appId, forceHttps) {
  const res = await api.put(`/applications/${appId}/certificate/force-https`, {
    force_https: forceHttps,
  });
  return res.data?.certificate;
}

export async function deleteCertificate(appId) {
  await api.delete(`/applications/${appId}/certificate`);
}
