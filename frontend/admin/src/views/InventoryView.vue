<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'

type Store = { id: number; name: string }
type VariantOption = { id: number; label: string }
type Balance = {
  id: number
  locationId: number
  productVariantId: number
  available: string
  inTransit: string
}
type Movement = {
  id: number
  type: string
  quantity: string
  notes: string | null
  createdAt: string | null
}
type Transfer = {
  id: number
  status: string
  fromLocationId: number
  toLocationId: number
  items: Array<{ productVariantId: number; quantity: string }>
}

const stores = ref<Store[]>([])
const variants = ref<VariantOption[]>([])
const balances = ref<Balance[]>([])
const movements = ref<Movement[]>([])
const transfers = ref<Transfer[]>([])
const loading = ref(true)
const error = ref('')
const saving = ref(false)

const adjustForm = reactive({
  storeId: '' as string | number,
  productVariantId: '' as string | number,
  quantity: '',
  reason: '',
})

const countForm = reactive({
  storeId: '' as string | number,
  productVariantId: '' as string | number,
  countedQuantity: '',
  notes: '',
})

const transferForm = reactive({
  fromStoreId: '' as string | number,
  toStoreId: '' as string | number,
  productVariantId: '' as string | number,
  quantity: '',
  notes: '',
})

async function loadStores() {
  const res = await api.get<{ data: Store[] }>('/stores?per_page=50')
  stores.value = res.data
  if (!adjustForm.storeId && stores.value[0]) {
    adjustForm.storeId = stores.value[0].id
    countForm.storeId = stores.value[0].id
    transferForm.fromStoreId = stores.value[0].id
    transferForm.toStoreId = stores.value[1]?.id ?? stores.value[0].id
  }
}

async function loadVariants() {
  const products = await api.get<{
    data: Array<{ name: string; variants?: Array<{ id: number; name: string; sku: string; isActive?: boolean }> }>
    meta?: { lastPage: number }
  }>('/products?per_page=200')

  let all = [...products.data]
  const lastPage = products.meta?.lastPage ?? 1
  for (let p = 2; p <= Math.min(lastPage, 5); p++) {
    const pageRes = await api.get<{ data: typeof products.data }>(`/products?per_page=200&page=${p}`)
    all = all.concat(pageRes.data)
  }

  variants.value = all.flatMap((p) =>
    (p.variants ?? [])
      .filter((v) => v.isActive !== false)
      .map((v) => ({
        id: v.id,
        label: `${p.name} — ${v.name} (${v.sku})`,
      })),
  )
}

async function loadInventory() {
  const storeId = Number(adjustForm.storeId || countForm.storeId)
  if (!storeId) {
    balances.value = []
    movements.value = []
    return
  }

  const [b, m, t] = await Promise.all([
    api.get<{ data: Balance[] }>(`/inventory/balances?storeId=${storeId}&per_page=50`),
    api.get<{ data: Movement[] }>(`/inventory/movements?storeId=${storeId}&per_page=20`),
    api.get<{ data: Transfer[] }>('/inventory/transfers?per_page=20'),
  ])
  balances.value = b.data
  movements.value = m.data
  transfers.value = t.data
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await loadStores()
    await loadVariants()
    await loadInventory()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load inventory'
  } finally {
    loading.value = false
  }
}

async function onAdjust() {
  saving.value = true
  error.value = ''
  try {
    await api.post('/inventory/adjustments', {
      storeId: Number(adjustForm.storeId),
      productVariantId: Number(adjustForm.productVariantId),
      quantity: adjustForm.quantity,
      reason: adjustForm.reason,
    })
    adjustForm.quantity = ''
    adjustForm.reason = ''
    await loadInventory()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Adjustment failed'
  } finally {
    saving.value = false
  }
}

async function onCount() {
  saving.value = true
  error.value = ''
  try {
    await api.post('/inventory/counts', {
      storeId: Number(countForm.storeId),
      notes: countForm.notes || null,
      items: [
        {
          productVariantId: Number(countForm.productVariantId),
          countedQuantity: countForm.countedQuantity,
        },
      ],
    })
    countForm.countedQuantity = ''
    countForm.notes = ''
    await loadInventory()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Count failed'
  } finally {
    saving.value = false
  }
}

async function onTransfer() {
  saving.value = true
  error.value = ''
  try {
    await api.post('/inventory/transfers', {
      fromStoreId: Number(transferForm.fromStoreId),
      toStoreId: Number(transferForm.toStoreId),
      notes: transferForm.notes || null,
      items: [
        {
          productVariantId: Number(transferForm.productVariantId),
          quantity: transferForm.quantity,
        },
      ],
    })
    transferForm.quantity = ''
    transferForm.notes = ''
    await loadInventory()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Transfer failed'
  } finally {
    saving.value = false
  }
}

async function onReceive(transferId: number) {
  saving.value = true
  error.value = ''
  try {
    await api.post(`/inventory/transfers/${transferId}/receive`)
    await loadInventory()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Receive failed'
  } finally {
    saving.value = false
  }
}

onMounted(refresh)
</script>

<template>
  <section class="space-y-6">
    <div>
      <h2 class="text-xl font-semibold tracking-tight">Inventory</h2>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Adjust stock, complete counts, and transfer between stores. All quantity changes post through the inventory ledger.
      </p>
    </div>

    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>

    <div v-if="!loading" class="grid gap-6 lg:grid-cols-3">
      <form class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4 space-y-3" @submit.prevent="onAdjust">
        <h3 class="font-semibold">Stock adjustment</h3>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select v-model="adjustForm.storeId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" @change="loadInventory">
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Variant</span>
          <select v-model="adjustForm.productVariantId" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option value="">—</option>
            <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Quantity (+/-)</span>
          <input v-model="adjustForm.quantity" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" placeholder="e.g. 5.0000 or -2.0000" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Reason</span>
          <input v-model="adjustForm.reason" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <button type="submit" class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm text-white disabled:opacity-60" :disabled="saving">
          Post adjustment
        </button>
      </form>

      <form class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4 space-y-3" @submit.prevent="onCount">
        <h3 class="font-semibold">Stock count</h3>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Store</span>
          <select v-model="countForm.storeId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Variant</span>
          <select v-model="countForm.productVariantId" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option value="">—</option>
            <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Counted quantity</span>
          <input v-model="countForm.countedQuantity" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Notes</span>
          <input v-model="countForm.notes" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <button type="submit" class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm text-white disabled:opacity-60" :disabled="saving">
          Complete count
        </button>
      </form>

      <form class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4 space-y-3" @submit.prevent="onTransfer">
        <h3 class="font-semibold">Transfer</h3>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">From store</span>
          <select v-model="transferForm.fromStoreId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">To store</span>
          <select v-model="transferForm.toStoreId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Variant</span>
          <select v-model="transferForm.productVariantId" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option value="">—</option>
            <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Quantity</span>
          <input v-model="transferForm.quantity" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Notes</span>
          <input v-model="transferForm.notes" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <button type="submit" class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm text-white disabled:opacity-60" :disabled="saving">
          Initiate transfer
        </button>
      </form>
    </div>

    <div v-if="!loading && transfers.length" class="overflow-x-auto rounded-lg border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Transfer</th>
            <th class="px-3 py-2 font-medium">From → To</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in transfers" :key="t.id" class="border-b border-[var(--line)]">
            <td class="px-3 py-2">#{{ t.id }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ t.fromLocationId }} → {{ t.toLocationId }}</td>
            <td class="px-3 py-2">{{ t.status }}</td>
            <td class="px-3 py-2 text-right">
              <button
                v-if="t.status === 'in_transit'"
                type="button"
                class="text-[var(--brand)] disabled:opacity-60"
                :disabled="saving"
                @click="onReceive(t.id)"
              >
                Receive
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="!loading" class="grid gap-6 lg:grid-cols-2">
      <div class="overflow-x-auto rounded-lg border border-[var(--line)]">
        <table class="min-w-full text-left text-sm">
          <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
            <tr>
              <th class="px-3 py-2 font-medium">Variant</th>
              <th class="px-3 py-2 font-medium">Available</th>
              <th class="px-3 py-2 font-medium">In transit</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="balances.length === 0">
              <td colspan="3" class="px-3 py-4 text-[var(--muted)]">No balances yet.</td>
            </tr>
            <tr v-for="b in balances" :key="b.id" class="border-b border-[var(--line)]">
              <td class="px-3 py-2 font-mono text-xs">#{{ b.productVariantId }}</td>
              <td class="px-3 py-2">{{ b.available }}</td>
              <td class="px-3 py-2">{{ b.inTransit }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="overflow-x-auto rounded-lg border border-[var(--line)]">
        <table class="min-w-full text-left text-sm">
          <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
            <tr>
              <th class="px-3 py-2 font-medium">Type</th>
              <th class="px-3 py-2 font-medium">Qty</th>
              <th class="px-3 py-2 font-medium">Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="movements.length === 0">
              <td colspan="3" class="px-3 py-4 text-[var(--muted)]">No movements yet.</td>
            </tr>
            <tr v-for="m in movements" :key="m.id" class="border-b border-[var(--line)]">
              <td class="px-3 py-2">{{ m.type }}</td>
              <td class="px-3 py-2">{{ m.quantity }}</td>
              <td class="px-3 py-2 text-[var(--muted)]">{{ m.notes ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>
