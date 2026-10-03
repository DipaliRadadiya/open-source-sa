{{-- Managed by the panel. Manual edits are overwritten on the next deploy.

     Grafana.

     Its own file rather than the shared one-container template for a single
     reason: the shared template renders `$environment`, and the admin password
     is a `$secret`. An app whose password is generated per site cannot use it.

     `GF_SECURITY_ADMIN_PASSWORD` is read on EVERY boot, not only the first, so
     this value is what the admin account's password is — which is why the
     installer keeps the stored secret rather than generating a new one on a
     re-provision. Regenerating here would silently change the password somebody
     has already saved.

     Grafana's documented quickstart leaves admin/admin with a prompt on first
     login. On a public URL that is a race between the owner and everyone else,
     so the panel sets one and the Credentials panel is where it is read. --}}
name: {{ $project }}

services:
  app:
    image: {{ $image }}
    @include('server.docker.apps.limits', ['limit' => $memoryLimit, 'cpus' => $cpuLimit])

    {{-- Loopback ONLY. Docker writes its own rules into the DOCKER chain ahead of
         the ones ufw manages, so the form everyone writes is reachable from the
         internet while the panel's Firewall page says the port is closed. --}}
    ports:
      - "127.0.0.1:{{ $appPort }}:{{ $containerPort }}"

    environment:
@foreach ($environment as $key => $value)
      {{ $key }}: "{{ $value }}"
@endforeach
      GF_SECURITY_ADMIN_PASSWORD: "{{ $secrets['GF_SECURITY_ADMIN_PASSWORD'] }}"

    volumes:
@foreach ($mounts as $path => $source)
      - {{ $source }}:{{ $path }}
@endforeach

volumes:
@foreach ($volumes as $name)
  {{ $name }}:
    external: true
@endforeach
