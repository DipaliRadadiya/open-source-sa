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
