{{-- Managed by the panel. Manual edits are overwritten on the next deploy. --}}
name: {{ $project }}

services:
  app:
    image: {{ $image }}
    restart: unless-stopped

    {{-- Published to loopback ONLY. Docker writes its own rules ahead of ufw's, so a plain
         `"port:port"` mapping binds 0.0.0.0 and is public even while the Firewall page says closed.
         The host's web server is what the world talks to, and it proxies here. --}}
    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

@if ($network !== null)
    {{-- Network the site joins, when one was chosen. The whole block, comment included, sits inside the
         conditional so a site without one renders byte-for-byte as before. --}}
    networks:
      {{ $network }}:
        {{-- The site's own name on the network (mapping form). Compose only aliases the service name,
             `app` in every generated file, so two sites on one network both answer to `app` and Docker's
             DNS picks one at random. The slug is unique per site. --}}
        aliases:
          - {{ $alias }}

@endif
    {{-- Only the site's own directory, nothing above it: a bind such as `/:/host` would hand over the
         machine. Also validated on save.
         Mounted at `$siteMount`, not a fixed `/app` (many images keep their program there); sites created
         earlier keep `/app`, see `Application::siteMountPath()`. --}}
    volumes:
      - {{ $documentRoot }}:{{ $siteMount }}
@foreach ($mounts as $mount)
      - {{ $mount['volume'] }}:{{ $mount['path'] }}
@endforeach

    {{-- Same file the Environment screen edits; the unit and the screen must name the same path. --}}
    env_file:
      - {{ $envPath }}

    {{-- A container with no ceiling can take the box down. Overridable per application; never absent. --}}
    mem_limit: {{ $memoryLimit }}
{{-- CPU quota in cores, only when one was set. The conditional holds exactly one line (this comment
     stays outside it) so a site without a limit renders byte-for-byte as before.
     Never write an empty value: Compose reads `cpus: ` as `0`, which means no limit. --}}
@if ($cpuLimit !== null)
    cpus: {{ $cpuLimit }}
@endif

    {{-- Bounded, to the local journal: Docker's default json-file driver has no max size, so a chatty
         container fills the disk. --}}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"
@if ($network !== null)

{{-- `external: true` is load-bearing: without it Compose creates `<project>_<network>` instead of
     joining the network chosen on the Docker page, and the site cannot reach its neighbour. With it a
     missing network fails loudly. The delete guard keeps it alive while this file names it. --}}
networks:
  {{ $network }}:
    external: true
@endif
@if ($mounts !== [])

{{-- Declared `external: true` like the network: without it Compose creates an empty
     `<project>_<name>` volume and the site starts with none of its data, which looks like data loss.
     Keyed by volume name, so a volume mounted at several paths is declared once. --}}
volumes:
@foreach (collect($mounts)->pluck('volume')->unique() as $volume)
  {{ $volume }}:
    external: true
@endforeach
@endif
