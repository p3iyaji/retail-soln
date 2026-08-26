<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <section class="max-w-3xl">
    <h2 class="text-xl font-semibold tracking-tight">Welcome</h2>
    <p class="mt-2 text-sm text-[var(--muted)]">
      Signed in as {{ auth.displayName }}. Use the navigation to open foundation modules.
      Full CRUD UIs land in later stories; stubs are ready for wiring.
    </p>

    <div class="mt-6 grid gap-3 sm:grid-cols-2">
      <RouterLink
        v-for="item in [
          { to: '/organization', label: 'Organization' },
          { to: '/stores', label: 'Stores' },
          { to: '/users', label: 'Users' },
          { to: '/settings', label: 'Settings' },
        ]"
        :key="item.to"
        :to="item.to"
        class="border border-[var(--line)] bg-[var(--panel)] px-4 py-3 text-sm hover:border-teal-300"
      >
        {{ item.label }}
      </RouterLink>
    </div>
  </section>
</template>
