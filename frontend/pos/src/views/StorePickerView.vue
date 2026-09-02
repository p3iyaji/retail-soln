<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usePosStore } from '@/stores/pos'

const auth = useAuthStore()
const pos = usePosStore()
const router = useRouter()

const stores = computed(() => auth.storeAccess)
const emptyAccess = computed(() => stores.value.length === 0)

async function pickStore(storeId: number) {
  pos.selectStore(storeId)
  await router.replace('/')
}

async function signOut() {
  await auth.logout()
  pos.clearStore()
  await router.replace('/login')
}
</script>

<template>
  <div class="flex min-h-full flex-col bg-[var(--surface)]">
    <header
      class="flex items-center gap-4 bg-[var(--chrome)] px-4 py-3 text-sm text-[var(--chrome-fg)]"
    >
      <img src="/logo.png" alt="Bag & Cartons Ltd" class="h-8 w-auto shrink-0 object-contain" />
      <strong class="font-display">Select store</strong>
      <span class="flex-1" />
      <span>{{ auth.displayName }}</span>
      <button
        type="button"
        class="min-h-11 rounded-[var(--radius)] border border-white/20 px-3 text-sm hover:bg-white/10"
        @click="signOut"
      >
        Sign out
      </button>
    </header>

    <main class="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center gap-4 px-4 py-10">
      <template v-if="emptyAccess">
        <h1 class="font-display text-xl font-semibold">No store access</h1>
        <p class="text-[var(--muted)]">
          Your account is not assigned to any store. Ask an organization admin to grant store access
          before using POS.
        </p>
      </template>

      <template v-else>
        <h1 class="font-display text-xl font-semibold">Choose a store</h1>
        <p class="text-sm text-[var(--muted)]">API calls will send this store as <code>X-Store-Id</code>.</p>
        <ul class="space-y-2">
          <li v-for="store in stores" :key="store.id">
            <button
              type="button"
              class="flex min-h-11 w-full items-center justify-between rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] px-4 py-3 text-left hover:border-[var(--primary)]"
              @click="pickStore(store.id)"
            >
              <span class="font-medium">{{ store.name }}</span>
              <span v-if="store.isDefault" class="text-xs text-[var(--muted)]">Default</span>
            </button>
          </li>
        </ul>
      </template>
    </main>
  </div>
</template>
