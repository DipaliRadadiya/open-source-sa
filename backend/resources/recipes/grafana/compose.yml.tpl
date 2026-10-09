{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Grafana: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  app:
    image: {{ image.app }}
    restart: unless-stopped
    mem_limit: {{ memory_limit }}
{{#if cpu_limit}}
    cpus: {{ cpu_limit }}
{{/if}}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    {{-- --}}
    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      GF_SECURITY_ADMIN_USER: "admin"
      GF_SERVER_SERVE_FROM_SUB_PATH: "false"
      GF_ANALYTICS_REPORTING_ENABLED: "false"
      GF_ANALYTICS_CHECK_FOR_UPDATES: "false"
      GF_AUTH_ANONYMOUS_ENABLED: "false"
      GF_SERVER_ROOT_URL: "{{ url }}"
      GF_SECURITY_ADMIN_PASSWORD: "{{ secret.GF_SECURITY_ADMIN_PASSWORD }}"

    volumes:
      - {{ volume.data }}:/var/lib/grafana

volumes:
  {{ volume.data }}:
    external: true
