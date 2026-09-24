{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
name: {{ $project }}

services:
  app:
    image: {{ $image }}
    restart: unless-stopped

    {{-- Published to loopback ONLY, and this is the single most important
         line in the file.

         Docker writes its own rules into the DOCKER chain ahead of the ones
         ufw manages, so `- "{{ $appPort }}:{{ $containerPort }}"` — the form
         everyone writes — binds 0.0.0.0 and is reachable from the internet
         while the panel's Firewall page says the port is closed. The page is
         not wrong about ufw; ufw is not what decides.

         nginx on the host is what the world talks to, and it proxies here. --}}
    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

@if ($network !== null)
    {{-- The network the site joins, when one was chosen on the Docker page.
         The whole block — this comment included — is inside the conditional so
         that a site without one renders the file byte-for-byte as it did
         before this field existed. A stray blank line would be harmless YAML
         and would still make every existing site's compose file "changed" on
         its next deploy, which is a diff nobody can tell from a real one. --}}
    networks:
      - {{ $network }}

@endif
    {{-- The site's own directory, and nothing above it. A bind mount is the
         one field that makes every other control cosmetic: `- /:/host` hands
         over the machine, and the site user does not have to be clever about
         it. Confined here, and validated again on save — a rule that only
         exists in a template is a rule the next code path forgets. --}}
    volumes:
      - {{ $documentRoot }}:/app

    {{-- The same file the Environment screen edits. They have to name the same
         path: when the unit and the screen each built their own, the screen
         edited one `.env` while the supervisor loaded another, and nobody
         could see why a variable had no effect. --}}
    env_file:
      - {{ $envPath }}

    {{-- A container with no ceiling can take the box down, and the panel with
         it. Overridable per application; never absent. --}}
    mem_limit: {{ $memoryLimit }}

    {{-- Bounded, and to the local journal rather than a file the panel does
         not rotate. Docker's default json-file driver has NO max size: a
         chatty container fills the disk and the first symptom is every site
         on the box failing to write. --}}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"
@if ($network !== null)

{{-- `external: true` is the load-bearing word.

     Without it Compose does not join the network on the Docker page — it
     CREATES one named `<project>_<network>` and joins that. The site comes up,
     reports healthy, and cannot reach the container it was put next to,
     because the two are on different networks that differ only by a prefix
     nobody sees. With it, Compose looks the name up and fails loudly if it is
     gone, which is the failure we want.

     The panel is what created it, and the delete guard is what keeps it alive
     while this file names it. --}}
networks:
  {{ $network }}:
    external: true
@endif
