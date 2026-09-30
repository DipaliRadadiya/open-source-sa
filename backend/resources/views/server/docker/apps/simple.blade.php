{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     The shared template for a one-container app.

     Ghost, NocoDB, Metabase and Wiki.js each have their own file because each is
     genuinely different — two services, a database to wire, environment shaped
     differently per app. These are not: one image, one port, one or two volumes,
     and whatever environment the type declares. Eight near-identical files would
     be eight places to forget the loopback binding.

     An app with something structural to say still writes its own template; this
     is the default, not a requirement. --}}
name: {{ $project }}

services:
  app:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit, 'cpus' => $cpuLimit])

    {{-- Loopback ONLY. Docker writes its own rules into the DOCKER chain ahead
         of the ones ufw manages, so the form everyone writes is reachable from
         the internet while the panel's Firewall page says the port is closed. --}}
    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"
@if ($environment !== [])

    environment:
@foreach ($environment as $key => $value)
      {{ $key }}: "{{ $value }}"
@endforeach
@endif
@if ($mounts !== [])

    volumes:
@foreach ($mounts as $path => $source)
      - {{ $source }}:{{ $path }}
@endforeach
@endif
@if ($volumes !== [])

{{-- Only NAMED volumes are declared here. A bind mount needs no declaration, and
     gating this block on the mount list instead meant a bind-only app emitted a
     `volumes:` key with nothing under it — which is not valid compose. --}}
volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
@endif
