# End-to-end automation

Scripts here drive a **real server** through the HTTP API and then check the claim
against Docker, the kernel, nginx or curl. They are not a substitute for the Pest and
node suites — those cover branches a live box cannot reach cheaply — they exist
because of what the suites keep missing.

Every bug the Docker stack has shipped had a green suite behind it: a `ports:` line
reachable from the internet while the Firewall page said closed, a one-click app whose
credentials 404'd, a crash-looping container reporting `active`, a container ceiling
silently applied to a bundled database. In each case the panel answered 200 and the
machine disagreed. **A command that succeeded is not a feature that works.**

## `docker-stack.sh`

```
HOST=panel.example.com USER_ID=2 bash tests/e2e/docker-stack.sh
```

Run it **on the panel's own box** (it needs `sudo docker` and `artisan`).

- `HOST` — the host suffix; the API is assumed at `api.$HOST`.
- `USER_ID` — the panel user to mint a token as. **Use a dedicated account, never the
  first admin.** The activity log cannot otherwise tell the harness's actions from a
  person's, and that has already made one deletion unattributable.

It covers 60 assertions across: stack gating and the capability refusals, the doctor
checks, networks, volumes, a container site end to end (provision, serve over http and
https once the certificate lands, loopback-only publishing, network alias resolution,
CPU and memory limits read back from the cgroup, volume mount, size including the
volume share, the compose editor's refusals, and deletion taking the containers,
files, vhost, network and volume with it), and the containerised database API
including distinct ports for two engines.

### Conventions worth keeping if you add to it

- **Unique tag per run** (`e2eNNN`), and a sweep on `trap EXIT`. A killed run used to
  leave a system user behind, and the next run's create was refused as a duplicate —
  which reads as "the API returned nothing".
- **One site at a time, deleted before the next.** Twelve container sites once took a
  5.9GB box offline.
- **The sweep revokes only the token this run minted.** Deleting all of the user's
  tokens meant one run's cleanup killed a concurrent run's credential mid-flight.
- **Print the response body when an id comes back empty.** Three harness bugs in a row
  presented as "the API returned nothing" because it was not printed.
- **Check credentials by using them.** `[ -n "$T" ]` passed on a PHP error message, so
  a run reported a minted token and then failed forty assertions for a reason none of
  them named.
- **Assert the contract, not your cleanup.** Deleting a network the site delete had
  already removed reported a 404 as a failure — when the 404 *was* the product working.
