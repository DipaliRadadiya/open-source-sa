
{{-- `external: true` is load-bearing: the panel creates this volume so it shows on the Docker page
     and is guarded by the same delete checks as a site's volume. Without it Compose makes its own and
     the panel neither sees nor protects it.
     Sits ABOVE `volumes:`, not inside the block, so no indented line follows it. --}}
volumes:
  {{ $volume }}:
    external: true
@if ($network !== null)

networks:
  {{ $network }}:
    external: true
@endif
