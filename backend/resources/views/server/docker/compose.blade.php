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
      {{ $network }}:
        {{-- The site's own name on the network, and the reason this is the
             mapping form rather than a one-line list.

             Compose registers the SERVICE name as an alias, and every file the
             panel generates names its service `app`. Two panel sites on one
             network therefore both answer to `app`, and Docker's DNS returns
             one of them at random — measured on the test box: four lookups of
             `app` from the same container gave 172.19.0.2 once and .3 three
             times. A user following any Docker tutorial types the service name,
             so the default was a coin flip that looks like it works.

             The slug is unique per site and is the name the UI tells people to
             use. `app` stays ambiguous — Compose adds it and there is no way to
             refuse — but nobody has to rely on it. --}}
        aliases:
          - {{ $alias }}

@endif
    {{-- The site's own directory, and nothing above it. A bind mount is the
         one field that makes every other control cosmetic: `- /:/host` hands
         over the machine, and the site user does not have to be clever about
         it. Confined here, and validated again on save — a rule that only
         exists in a template is a rule the next code path forgets. --}}
    volumes:
      - {{ $documentRoot }}:/app
@foreach ($mounts as $mount)
      - {{ $mount['volume'] }}:{{ $mount['path'] }}
@endforeach

    {{-- The same file the Environment screen edits. They have to name the same
         path: when the unit and the screen each built their own, the screen
         edited one `.env` while the supervisor loaded another, and nobody
         could see why a variable had no effect. --}}
    env_file:
      - {{ $envPath }}

    {{-- A container with no ceiling can take the box down, and the panel with
         it. Overridable per application; never absent. --}}
    mem_limit: {{ $memoryLimit }}
{{-- A CPU quota in cores, and ONLY when one was set.

     The conditional contains exactly one line and nothing else — no blank
     lines, and this comment deliberately OUTSIDE it. A site with no CPU limit
     has to render this file byte-for-byte as it did before the field existed:
     a stray blank line is harmless YAML that still makes every existing site's
     compose file "changed" on its next deploy, which is a diff nobody can tell
     from a real one. Written with a blank line inside the branch, that is
     exactly what happened, and the test comparing the two renders is what
     caught it.

     Never written with an empty value either. Compose reads `cpus: ` as `0`,
     and `0` means no limit — so the key that looks like a limit would be the
     one thing that removes it. --}}
@if ($cpuLimit !== null)
    cpus: {{ $cpuLimit }}
@endif

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
@if ($mounts !== [])

{{-- Declared `external: true` for the same reason the network is: the panel
     created these on the Docker page, and Compose must look them up rather than
     make its own. Without it Compose creates `<project>_<name>` — a DIFFERENT,
     empty volume — and the site comes up with none of its data while a volume
     full of it sits unreferenced. That is the failure mode that looks like data
     loss and is not, which is worse than an error, because somebody restores a
     backup over the top of a perfectly good volume.

     Keyed by volume name, so two mounts of the same volume at different paths
     declare it once. --}}
volumes:
@foreach (collect($mounts)->pluck('volume')->unique() as $volume)
  {{ $volume }}:
    external: true
@endforeach
@endif
