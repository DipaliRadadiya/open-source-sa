{{-- Managed by the panel. Manual edits are overwritten on the next deploy.
     Chatwoot: generated from the golden fixture by template-from-golden.php (RC-04). --}}
name: {{ project }}

services:
  rails:
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

    ports:
      - "127.0.0.1:{{ app_port }}:{{ container_port }}"

    environment:
      RAILS_ENV: production
      NODE_ENV: production
      INSTALLATION_ENV: docker
      {{-- --}}
      FRONTEND_URL: "{{ url }}"
      {{-- --}}
      SECRET_KEY_BASE: "{{ secret.SECRET_KEY_BASE }}"
      POSTGRES_HOST: db
      POSTGRES_USERNAME: chatwoot
      POSTGRES_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"
      POSTGRES_DATABASE: chatwoot
      REDIS_URL: "redis://:{{ secret.REDIS_PASSWORD }}@redis:6379"
      {{-- --}}
      ENABLE_ACCOUNT_SIGNUP: "false"

    {{-- --}}
    entrypoint: docker/entrypoints/rails.sh
    command: ["sh", "-c", "bundle exec rails db:chatwoot_prepare && bundle exec rails s -p {{ container_port }} -b 0.0.0.0"]

    volumes:
      - {{ volume.storage }}:/app/storage

    depends_on:
      - db
      - redis

  sidekiq:
    image: {{ image.app }}
    {{-- --}}
    restart: unless-stopped
    mem_limit: 1g
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      RAILS_ENV: production
      NODE_ENV: production
      INSTALLATION_ENV: docker
      FRONTEND_URL: "{{ url }}"
      SECRET_KEY_BASE: "{{ secret.SECRET_KEY_BASE }}"
      POSTGRES_HOST: db
      POSTGRES_USERNAME: chatwoot
      POSTGRES_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"
      POSTGRES_DATABASE: chatwoot
      REDIS_URL: "redis://:{{ secret.REDIS_PASSWORD }}@redis:6379"

    command: ["bundle", "exec", "sidekiq", "-C", "config/sidekiq.yml"]

    {{-- --}}
    volumes:
      - {{ volume.storage }}:/app/storage

    depends_on:
      - db
      - redis

  db:
    image: {{ image.db }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    environment:
      POSTGRES_DB: chatwoot
      POSTGRES_USER: chatwoot
      {{-- --}}
      POSTGRES_PASSWORD: "{{ secret.POSTGRES_PASSWORD }}"

    volumes:
      - {{ volume.db }}:/var/lib/postgresql/data

  redis:
    image: {{ image.redis }}
    restart: unless-stopped
    mem_limit: {{ db_memory_limit }}
    logging:
      driver: json-file
      options:
        max-size: "10m"
        max-file: "3"

    {{-- --}}
    command: ["redis-server", "--requirepass", "{{ secret.REDIS_PASSWORD }}"]

    volumes:
      - {{ volume.redis }}:/data

volumes:
  {{ volume.storage }}:
    external: true
  {{ volume.db }}:
    external: true
  {{ volume.redis }}:
    external: true
