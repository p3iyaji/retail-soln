<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import {
  ApiError,
  formatPortalMoney,
  type CartTotals,
  type FulfilmentMode,
  type PaymentProvider,
} from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

const cart = useCartStore()
const auth = useAuthStore()
const router = useRouter()

const fulfilmentMode = ref<FulfilmentMode>('click_collect')
const storeId = ref<number | null>(null)
const addressId = ref<number | null>(null)
const contactPhone = ref('')
const paymentProvider = ref<PaymentProvider>('manual')
const previewTotals = ref<CartTotals | null>(null)
const previewError = ref<string | null>(null)
const placing = ref(false)
const placeError = ref<string | null>(null)

const addresses = computed(() => auth.account?.customer?.addresses ?? [])

onMounted(async () => {
  if (!auth.account) {
    try {
      await auth.fetchMe()
    } catch {
      await router.push({ name: 'login', query: { redirect: '/shop/checkout' } })
      return
    }
  }
  await cart.fetchCart()
  await cart.loadStores()
  if (cart.stores[0]) {
    storeId.value = cart.stores[0].id
  }
  contactPhone.value = auth.account?.customer?.phone ?? ''
  const defaultAddress = addresses.value.find((a) => a.isDefault) ?? addresses.value[0]
  if (defaultAddress) {
    addressId.value = defaultAddress.id
  }
  await refreshPreview()
})

watch([fulfilmentMode, storeId, addressId, contactPhone], () => {
  void refreshPreview()
})

async function refreshPreview() {
  previewError.value = null
  if (cart.isEmpty) {
    previewTotals.value = null
    return
  }
  if (fulfilmentMode.value === 'home_delivery' && !addressId.value) {
    previewTotals.value = null
    previewError.value = 'Add a delivery address to continue.'
    return
  }
  if (
    (fulfilmentMode.value === 'click_collect' || fulfilmentMode.value === 'store_pickup') &&
    !storeId.value
  ) {
    previewTotals.value = null
    previewError.value = 'Select a store.'
    return
  }
  try {
    const res = await cart.preview({
      fulfilmentMode: fulfilmentMode.value,
      storeId: storeId.value,
      addressId: fulfilmentMode.value === 'home_delivery' ? addressId.value : null,
      contactPhone: contactPhone.value || null,
    })
    previewTotals.value = res.data.totals
  } catch (err) {
    previewTotals.value = null
    previewError.value = err instanceof ApiError ? err.message : 'Could not preview checkout'
  }
}

function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID().replace(/-/g, '').slice(0, 64)
  }
  return `po_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`
}

async function placeOrder() {
  placeError.value = null
  placing.value = true
  let placedOrderId: number | null = null
  try {
    const origin = window.location.origin
    const order = await cart.placeOrder(
      {
        fulfilmentMode: fulfilmentMode.value,
        storeId: storeId.value,
        addressId: fulfilmentMode.value === 'home_delivery' ? addressId.value : null,
        contactPhone: contactPhone.value || null,
        paymentProvider: paymentProvider.value,
        successUrl: `${origin}/shop/confirmation`,
        cancelUrl: `${origin}/shop/checkout`,
      },
      newIdempotencyKey(),
    )
    placedOrderId = order.id

    if (paymentProvider.value === 'manual') {
      try {
        await cart.confirmManual(order.id)
      } catch (confirmErr) {
        placeError.value =
          confirmErr instanceof ApiError ? confirmErr.message : 'Payment confirm failed'
        await router.push({ name: 'confirmation', query: { orderId: String(order.id) } })
        return
      }
    } else if (order.payment?.redirectUrl) {
      window.location.href = order.payment.redirectUrl
      return
    }

    await router.push({ name: 'confirmation', query: { orderId: String(order.id) } })
  } catch (err) {
    placeError.value = err instanceof ApiError ? err.message : 'Place order failed'
    if (err instanceof ApiError && err.body.errors) {
      const stock = err.body.errors.stock?.[0]
      if (stock) placeError.value = stock
    }
    if (placedOrderId != null) {
      await router.push({ name: 'confirmation', query: { orderId: String(placedOrderId) } })
    }
  } finally {
    placing.value = false
  }
}

const displayTotals = computed(() => previewTotals.value ?? cart.totals)
</script>

<template>
  <div class="mx-auto grid max-w-[960px] gap-6 px-5 py-7 pb-12 md:grid-cols-[1fr_320px]">
    <h1 class="brand col-span-full text-2xl font-semibold tracking-tight">Checkout</h1>

    <div v-if="cart.isEmpty" class="col-span-full text-sm text-[var(--muted)]">
      Your cart is empty.
      <RouterLink :to="{ name: 'shop' }" class="text-[var(--brand)] underline">Browse products</RouterLink>
    </div>

    <template v-else>
      <div>
        <section class="mb-4 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-5">
          <h2 class="brand mb-3.5 text-[15px] font-semibold">Fulfilment</h2>
          <label
            class="mb-2 flex cursor-pointer gap-3 rounded-[var(--radius)] border p-3"
            :class="
              fulfilmentMode === 'home_delivery'
                ? 'border-[var(--brand)] bg-[var(--brand-soft)]'
                : 'border-[var(--line)]'
            "
          >
            <input v-model="fulfilmentMode" type="radio" value="home_delivery" class="mt-1" />
            <span>
              <strong class="block text-sm">Home delivery</strong>
              <span class="text-xs text-[var(--muted)]">Fee at confirm</span>
            </span>
          </label>
          <label
            class="mb-2 flex cursor-pointer gap-3 rounded-[var(--radius)] border p-3"
            :class="
              fulfilmentMode === 'click_collect'
                ? 'border-[var(--brand)] bg-[var(--brand-soft)]'
                : 'border-[var(--line)]'
            "
          >
            <input v-model="fulfilmentMode" type="radio" value="click_collect" class="mt-1" />
            <span>
              <strong class="block text-sm">Click &amp; collect</strong>
              <span class="text-xs text-[var(--muted)]">Ready when confirmed</span>
            </span>
          </label>
          <label
            class="flex cursor-pointer gap-3 rounded-[var(--radius)] border p-3"
            :class="
              fulfilmentMode === 'store_pickup'
                ? 'border-[var(--brand)] bg-[var(--brand-soft)]'
                : 'border-[var(--line)]'
            "
          >
            <input v-model="fulfilmentMode" type="radio" value="store_pickup" class="mt-1" />
            <span>
              <strong class="block text-sm">Store pickup</strong>
              <span class="text-xs text-[var(--muted)]">Choose a store</span>
            </span>
          </label>
        </section>

        <section
          v-if="fulfilmentMode !== 'home_delivery'"
          class="mb-4 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-5"
        >
          <h2 class="brand mb-3.5 text-[15px] font-semibold">Collecting store</h2>
          <label class="mb-1 block text-xs text-[var(--muted)]" for="store">Store</label>
          <select
            id="store"
            v-model.number="storeId"
            class="mb-3 h-10 w-full rounded border border-[var(--line)] px-2.5 text-sm"
          >
            <option v-for="s in cart.stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
          <label class="mb-1 block text-xs text-[var(--muted)]" for="phone">Contact phone</label>
          <input
            id="phone"
            v-model="contactPhone"
            type="tel"
            class="h-10 w-full rounded border border-[var(--line)] px-2.5 text-sm"
          />
        </section>

        <section
          v-else
          class="mb-4 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-5"
        >
          <h2 class="brand mb-3.5 text-[15px] font-semibold">Delivery address</h2>
          <p v-if="addresses.length === 0" class="text-sm text-[var(--muted)]">
            No addresses yet.
            <RouterLink :to="{ name: 'addresses' }" class="text-[var(--brand)] underline">Add one</RouterLink>
          </p>
          <template v-else>
            <label class="mb-1 block text-xs text-[var(--muted)]" for="address">Address</label>
            <select
              id="address"
              v-model.number="addressId"
              class="mb-3 h-10 w-full rounded border border-[var(--line)] px-2.5 text-sm"
            >
              <option v-for="a in addresses" :key="a.id" :value="a.id">
                {{ a.line1 }}{{ a.city ? `, ${a.city}` : '' }}
              </option>
            </select>
            <label class="mb-1 block text-xs text-[var(--muted)]" for="phone-d">Contact phone</label>
            <input
              id="phone-d"
              v-model="contactPhone"
              type="tel"
              class="h-10 w-full rounded border border-[var(--line)] px-2.5 text-sm"
            />
          </template>
        </section>

        <section class="mb-4 rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-5">
          <h2 class="brand mb-3.5 text-[15px] font-semibold">Payment</h2>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="provider in (['manual', 'paystack', 'stripe'] as PaymentProvider[])"
              :key="provider"
              type="button"
              class="rounded-[var(--radius)] border px-3.5 py-2.5 text-[13px] font-semibold capitalize"
              :class="
                paymentProvider === provider
                  ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand)]'
                  : 'border-[var(--line)]'
              "
              @click="paymentProvider = provider"
            >
              {{ provider === 'manual' ? 'Manual (test)' : provider }}
            </button>
          </div>
        </section>
      </div>

      <aside
        class="sticky top-4 h-fit rounded-[var(--radius)] border border-[var(--line)] bg-[var(--panel)] p-5"
      >
        <p class="mb-3 text-xs font-semibold text-[var(--ember)]">
          Stock is checked on place order (reservation comes later)
        </p>
        <div class="mb-2 flex justify-between text-sm text-[var(--muted)]">
          <span>Items</span>
          <span>{{ formatPortalMoney(displayTotals.subtotal) }}</span>
        </div>
        <div class="mb-2 flex justify-between text-sm text-[var(--muted)]">
          <span>Tax</span>
          <span>{{ formatPortalMoney(displayTotals.taxTotal) }}</span>
        </div>
        <div class="mb-2 flex justify-between text-sm text-[var(--muted)]">
          <span>{{ fulfilmentMode === 'home_delivery' ? 'Delivery' : 'Collection' }}</span>
          <span>{{ formatPortalMoney(displayTotals.deliveryFee) }}</span>
        </div>
        <div
          class="brand my-3.5 flex justify-between border-t border-[var(--line)] pt-3 text-xl font-bold text-[var(--brand)]"
        >
          <span>Pay now</span>
          <span>{{ formatPortalMoney(displayTotals.grandTotal) }}</span>
        </div>
        <p v-if="previewError" class="mb-3 text-xs text-[var(--danger)]">{{ previewError }}</p>
        <p v-if="placeError" class="mb-3 text-xs text-[var(--danger)]">{{ placeError }}</p>
        <button
          type="button"
          class="brand flex h-12 w-full items-center justify-center rounded-[var(--radius)] bg-[var(--brand)] text-[15px] font-semibold text-[var(--brand-fg)] disabled:opacity-50"
          :disabled="placing || Boolean(previewError)"
          @click="placeOrder"
        >
          {{ placing ? 'Placing…' : 'Place order' }}
        </button>
      </aside>
    </template>
  </div>
</template>
