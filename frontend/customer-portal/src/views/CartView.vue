<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { formatPortalMoney } from '@/api/client'
import { useCartStore } from '@/stores/cart'

const cart = useCartStore()
const router = useRouter()
const busy = ref(false)

onMounted(() => {
  void cart.fetchCart()
})

async function setQty(lineId: number, raw: string) {
  const qty = Number(raw)
  if (!Number.isFinite(qty) || qty < 0) {
    cart.error = 'Enter a valid quantity.'
    return
  }
  busy.value = true
  try {
    await cart.updateQty(lineId, qty)
  } catch {
    // cart.error already set by the store
  } finally {
    busy.value = false
  }
}

async function remove(lineId: number) {
  busy.value = true
  try {
    await cart.removeLine(lineId)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto grid max-w-[900px] gap-6 px-5 py-7 md:grid-cols-[1fr_300px]">
    <h1 class="brand col-span-full mb-0 text-2xl font-semibold tracking-tight">Your cart</h1>

    <p v-if="cart.loading && cart.isEmpty" class="col-span-full text-sm text-[var(--muted)]">Loading…</p>
    <p v-else-if="cart.error" class="col-span-full text-sm text-[var(--danger)]">{{ cart.error }}</p>

    <template v-else-if="cart.isEmpty">
      <div class="col-span-full rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-8 text-center">
        <p class="text-[var(--muted)]">Your cart is empty.</p>
        <RouterLink
          :to="{ name: 'shop' }"
          class="mt-4 inline-flex h-11 items-center rounded-[var(--radius)] bg-[var(--brand)] px-5 text-sm font-semibold text-[var(--brand-fg)] no-underline"
        >
          Continue shopping
        </RouterLink>
      </div>
    </template>

    <template v-else>
      <div class="overflow-hidden rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)]">
        <div
          v-for="line in cart.lines"
          :key="line.id"
          class="grid grid-cols-[72px_1fr_auto] items-center gap-3 border-b border-[var(--line)] p-4 last:border-b-0"
        >
          <div class="h-[72px] w-[72px] overflow-hidden rounded bg-[#E8ECF0]">
            <img
              v-if="line.imageUrl"
              :src="line.imageUrl"
              :alt="line.productName"
              class="h-full w-full object-cover"
            />
          </div>
          <div>
            <div class="text-sm font-semibold">{{ line.productName }}</div>
            <div class="font-mono text-[11px] text-[var(--muted)]">{{ line.sku }}</div>
            <div class="mt-2 flex items-center gap-2 text-sm text-[var(--muted)]">
              <label class="sr-only" :for="`qty-${line.id}`">Quantity</label>
              <input
                :id="`qty-${line.id}`"
                type="number"
                min="0"
                step="1"
                class="h-9 w-16 rounded border border-[var(--line)] px-2 text-sm"
                :value="Number(line.qty)"
                :disabled="busy"
                @change="setQty(line.id, ($event.target as HTMLInputElement).value)"
              />
              <button
                type="button"
                class="text-xs text-[var(--danger)] underline disabled:opacity-50"
                :disabled="busy"
                @click="remove(line.id)"
              >
                Remove
              </button>
            </div>
          </div>
          <div class="font-mono text-sm font-semibold">{{ formatPortalMoney(line.lineTotal) }}</div>
        </div>
      </div>

      <aside class="h-fit rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-5">
        <h2 class="brand mb-3.5 text-base font-semibold">Summary</h2>
        <div class="mb-2 flex justify-between text-sm text-[var(--muted)]">
          <span>Subtotal</span>
          <span>{{ formatPortalMoney(cart.totals.subtotal) }}</span>
        </div>
        <div class="mb-2 flex justify-between text-sm text-[var(--muted)]">
          <span>Tax (est.)</span>
          <span>{{ formatPortalMoney(cart.totals.taxTotal) }}</span>
        </div>
        <div class="mb-2 flex justify-between text-sm text-[var(--muted)]">
          <span>Delivery</span>
          <span>Calculated next</span>
        </div>
        <div
          class="brand my-3.5 flex justify-between border-t border-[var(--line)] pt-3 text-xl font-bold text-[var(--brand)]"
        >
          <span>Total</span>
          <span>{{ formatPortalMoney(cart.totals.grandTotal) }}</span>
        </div>
        <button
          type="button"
          class="brand flex h-12 w-full items-center justify-center rounded-[var(--radius)] bg-[var(--brand)] text-[15px] font-semibold text-[var(--brand-fg)]"
          @click="router.push({ name: 'checkout' })"
        >
          Checkout
        </button>
      </aside>
    </template>
  </div>
</template>
