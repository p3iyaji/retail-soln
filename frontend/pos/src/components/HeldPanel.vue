<script setup lang="ts">
import { onMounted } from 'vue'
import type { SaleResource } from '@/api/posSales'
import { usePosStore } from '@/stores/pos'

const pos = usePosStore()

onMounted(() => {
  void pos.loadHeldSales()
})

function resume(sale: SaleResource): void {
  pos.resumeHeldSale(sale)
}

function formatMoney(value: string): string {
  const n = Number(value)
  return Number.isFinite(n) ? n.toFixed(2) : value
}
</script>

<template>
  <div class="px-4 py-4">
    <p class="text-sm text-[var(--muted)]">Suspended sales for this store</p>

    <p v-if="pos.heldBusy" class="mt-4 text-sm text-[var(--muted)]">Loading…</p>
    <p v-else-if="pos.heldSales.length === 0" class="mt-4 text-sm text-[var(--muted)]">
      No held sales.
    </p>

    <ul v-else class="mt-3 max-h-64 space-y-2 overflow-y-auto">
      <li v-for="sale in pos.heldSales" :key="sale.id">
        <button
          type="button"
          class="flex w-full items-center justify-between rounded-[var(--radius)] border border-[var(--line)] px-3 py-2 text-left hover:bg-[var(--surface)]"
          @click="resume(sale)"
        >
          <span class="text-sm">
            #{{ sale.id }}
            <span class="text-[var(--muted)]">· {{ sale.items.length }} line(s)</span>
          </span>
          <span class="font-mono-pos text-sm font-medium">{{ formatMoney(sale.total) }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>
