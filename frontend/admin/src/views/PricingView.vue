<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

type Store = { id: number; name: string }
type VariantOption = { id: number; label: string }

type Price = {
  id: number
  priceListId: number
  productVariantId: number
  amount: string
}

type PriceList = {
  id: number
  name: string
  storeId: number | null
  isActive: boolean
  isDefault: boolean
  prices?: Price[]
}

type PromoRule = {
  id?: number
  type: 'percent' | 'fixed' | 'bogo'
  value: string
  productVariantId: number | null
  buyQty: string | null
  getQty: string | null
  getDiscountPercent: string | null
}

type Promotion = {
  id: number
  name: string
  storeId: number | null
  startsAt: string | null
  endsAt: string | null
  isActive: boolean
  rules: PromoRule[]
}

type ResolveResult = {
  unitPrice: string
  listPrice: string
  discountAmount: string
  netPrice: string
  grossPrice: string
  tax: { name: string; rate: string; inclusive: boolean; amount: string }
  appliedPromotions: Array<{ name: string; type: string; discountAmount: string }>
}

const auth = useAuthStore()
const canManagePricing = computed(() => auth.user?.permissions?.includes('pricing.manage') === true)
const canManagePromos = computed(() => auth.user?.permissions?.includes('promotions.manage') === true)
const canResolve = computed(
  () =>
    canManagePricing.value ||
    canManagePromos.value ||
    auth.user?.permissions?.includes('sales.create') === true,
)

const stores = ref<Store[]>([])
const variants = ref<VariantOption[]>([])
const priceLists = ref<PriceList[]>([])
const promotions = ref<Promotion[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const resolveResult = ref<ResolveResult | null>(null)

const listForm = reactive({
  name: 'Retail',
  storeId: '' as string | number,
  isDefault: true,
  isActive: true,
})

const priceForm = reactive({
  priceListId: '' as string | number,
  productVariantId: '' as string | number,
  amount: '0.0000',
})

const promoForm = reactive({
  name: '',
  storeId: '' as string | number,
  startsAt: '',
  endsAt: '',
  isActive: true,
  type: 'percent' as PromoRule['type'],
  value: '10.0000',
  productVariantId: '' as string | number,
  buyQty: '2.0000',
  getQty: '1.0000',
  getDiscountPercent: '100.0000',
})

const resolveForm = reactive({
  storeId: '' as string | number,
  productVariantId: '' as string | number,
  qty: '1.0000',
})

async function loadLookups() {
  const [storeRes, productRes] = await Promise.all([
    api.get<{ data: Store[] }>('/stores?per_page=50'),
    api.get<{
      data: Array<{ name: string; variants?: Array<{ id: number; name: string; sku: string }> }>
    }>('/products?per_page=50'),
  ])
  stores.value = storeRes.data
  variants.value = productRes.data.flatMap((p) =>
    (p.variants ?? []).map((v) => ({
      id: v.id,
      label: `${p.name} — ${v.name} (${v.sku})`,
    })),
  )
  if (!resolveForm.storeId && stores.value[0]) {
    resolveForm.storeId = stores.value[0].id
  }
}

async function loadPricing() {
  const tasks: Promise<unknown>[] = []
  if (canManagePricing.value) {
    tasks.push(
      api.get<{ data: PriceList[] }>('/price-lists').then((res) => {
        priceLists.value = res.data
        if (!priceForm.priceListId && res.data[0]) {
          priceForm.priceListId = res.data[0].id
        }
      }),
    )
  }
  if (canManagePromos.value) {
    tasks.push(
      api.get<{ data: Promotion[] }>('/promotions').then((res) => {
        promotions.value = res.data
      }),
    )
  }
  await Promise.all(tasks)
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await loadLookups()
    await loadPricing()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load pricing'
  } finally {
    loading.value = false
  }
}

async function createPriceList() {
  if (!canManagePricing.value) return
  saving.value = true
  error.value = ''
  try {
    await api.post('/price-lists', {
      name: listForm.name,
      storeId: listForm.storeId === '' ? null : Number(listForm.storeId),
      isDefault: listForm.isDefault,
      isActive: listForm.isActive,
    })
    await loadPricing()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to create price list'
  } finally {
    saving.value = false
  }
}

async function createPrice() {
  if (!canManagePricing.value) return
  saving.value = true
  error.value = ''
  try {
    await api.post('/prices', {
      priceListId: Number(priceForm.priceListId),
      productVariantId: Number(priceForm.productVariantId),
      amount: priceForm.amount,
    })
    await loadPricing()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to save price'
  } finally {
    saving.value = false
  }
}

async function createPromotion() {
  if (!canManagePromos.value) return
  saving.value = true
  error.value = ''
  try {
    const rule: Record<string, unknown> = {
      type: promoForm.type,
      value: promoForm.type === 'bogo' ? '0' : promoForm.value,
      productVariantId: promoForm.productVariantId === '' ? null : Number(promoForm.productVariantId),
    }
    if (promoForm.type === 'bogo') {
      rule.buyQty = promoForm.buyQty
      rule.getQty = promoForm.getQty
      rule.getDiscountPercent = promoForm.getDiscountPercent
    }
    await api.post('/promotions', {
      name: promoForm.name,
      storeId: promoForm.storeId === '' ? null : Number(promoForm.storeId),
      startsAt: promoForm.startsAt || null,
      endsAt: promoForm.endsAt || null,
      isActive: promoForm.isActive,
      rules: [rule],
    })
    promoForm.name = ''
    await loadPricing()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to create promotion'
  } finally {
    saving.value = false
  }
}

async function runResolve() {
  if (!canResolve.value) return
  saving.value = true
  error.value = ''
  resolveResult.value = null
  try {
    const res = await api.post<{ data: ResolveResult }>('/pricing/resolve', {
      storeId: Number(resolveForm.storeId),
      variantId: Number(resolveForm.productVariantId),
      qty: resolveForm.qty,
    })
    resolveResult.value = res.data
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Resolve failed'
  } finally {
    saving.value = false
  }
}

onMounted(refresh)
</script>

<template>
  <div class="space-y-8">
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading…</p>
    <p v-else-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </p>

    <section v-if="canManagePricing" class="space-y-4">
      <h2 class="text-base font-semibold">Price lists</h2>
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Name</span>
          <input v-model="listForm.name" class="rounded-md border border-[var(--line)] px-3 py-1.5" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Store (optional)</span>
          <select v-model="listForm.storeId" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option value="">Org default</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="listForm.isDefault" type="checkbox" />
          Default
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="listForm.isActive" type="checkbox" />
          Active
        </label>
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white disabled:opacity-60"
          :disabled="saving"
          @click="createPriceList"
        >
          Add list
        </button>
      </div>

      <ul class="divide-y divide-[var(--line)] rounded-md border border-[var(--line)] bg-[var(--panel)] text-sm">
        <li v-for="list in priceLists" :key="list.id" class="px-3 py-2">
          <span class="font-medium">{{ list.name }}</span>
          <span class="text-[var(--muted)]">
            · {{ list.storeId ? `store #${list.storeId}` : 'org' }}
            · {{ list.isDefault ? 'default' : 'named' }}
            · {{ list.prices?.length ?? 0 }} prices
          </span>
        </li>
        <li v-if="priceLists.length === 0" class="px-3 py-2 text-[var(--muted)]">No price lists yet.</li>
      </ul>

      <h3 class="text-sm font-semibold">Add / update price</h3>
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">List</span>
          <select v-model="priceForm.priceListId" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option v-for="list in priceLists" :key="list.id" :value="list.id">{{ list.name }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Variant</span>
          <select v-model="priceForm.productVariantId" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option value="">Select…</option>
            <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Amount</span>
          <input v-model="priceForm.amount" class="w-32 rounded-md border border-[var(--line)] px-3 py-1.5 font-mono" />
        </label>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="saving || !priceForm.priceListId || !priceForm.productVariantId"
          @click="createPrice"
        >
          Save price
        </button>
      </div>
    </section>

    <section v-if="canManagePromos" class="space-y-4">
      <h2 class="text-base font-semibold">Promotions</h2>
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Name</span>
          <input v-model="promoForm.name" class="rounded-md border border-[var(--line)] px-3 py-1.5" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Store (optional)</span>
          <select v-model="promoForm.storeId" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option value="">All stores</option>
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Starts</span>
          <input v-model="promoForm.startsAt" type="datetime-local" class="rounded-md border border-[var(--line)] px-3 py-1.5" />
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Ends</span>
          <input v-model="promoForm.endsAt" type="datetime-local" class="rounded-md border border-[var(--line)] px-3 py-1.5" />
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="promoForm.isActive" type="checkbox" />
          Active
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Type</span>
          <select v-model="promoForm.type" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option value="percent">Percent</option>
            <option value="fixed">Fixed off</option>
            <option value="bogo">BOGO</option>
          </select>
        </label>
        <label v-if="promoForm.type !== 'bogo'" class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Value</span>
          <input v-model="promoForm.value" class="w-28 rounded-md border border-[var(--line)] px-3 py-1.5 font-mono" />
        </label>
        <template v-else>
          <label class="text-sm">
            <span class="mb-1 block text-[var(--muted)]">Buy qty</span>
            <input v-model="promoForm.buyQty" class="w-24 rounded-md border border-[var(--line)] px-3 py-1.5 font-mono" />
          </label>
          <label class="text-sm">
            <span class="mb-1 block text-[var(--muted)]">Get qty</span>
            <input v-model="promoForm.getQty" class="w-24 rounded-md border border-[var(--line)] px-3 py-1.5 font-mono" />
          </label>
          <label class="text-sm">
            <span class="mb-1 block text-[var(--muted)]">Get % off</span>
            <input
              v-model="promoForm.getDiscountPercent"
              class="w-24 rounded-md border border-[var(--line)] px-3 py-1.5 font-mono"
            />
          </label>
        </template>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Variant (optional)</span>
          <select v-model="promoForm.productVariantId" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option value="">All variants</option>
            <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
          </select>
        </label>
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-1.5 text-sm text-white disabled:opacity-60"
          :disabled="saving || !promoForm.name"
          @click="createPromotion"
        >
          Add promotion
        </button>
      </div>

      <ul class="divide-y divide-[var(--line)] rounded-md border border-[var(--line)] bg-[var(--panel)] text-sm">
        <li v-for="promo in promotions" :key="promo.id" class="px-3 py-2">
          <span class="font-medium">{{ promo.name }}</span>
          <span class="text-[var(--muted)]">
            · {{ promo.isActive ? 'active' : 'inactive' }}
            · {{ promo.rules.map((r) => r.type).join(', ') }}
          </span>
        </li>
        <li v-if="promotions.length === 0" class="px-3 py-2 text-[var(--muted)]">No promotions yet.</li>
      </ul>
    </section>

    <section v-if="canResolve" class="space-y-4">
      <h2 class="text-base font-semibold">Smoke resolve</h2>
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Store</span>
          <select v-model="resolveForm.storeId" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option v-for="s in stores" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Variant</span>
          <select v-model="resolveForm.productVariantId" class="rounded-md border border-[var(--line)] px-3 py-1.5">
            <option value="">Select…</option>
            <option v-for="v in variants" :key="v.id" :value="v.id">{{ v.label }}</option>
          </select>
        </label>
        <label class="text-sm">
          <span class="mb-1 block text-[var(--muted)]">Qty</span>
          <input v-model="resolveForm.qty" class="w-24 rounded-md border border-[var(--line)] px-3 py-1.5 font-mono" />
        </label>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-1.5 text-sm hover:bg-stone-50 disabled:opacity-60"
          :disabled="saving || !resolveForm.storeId || !resolveForm.productVariantId"
          @click="runResolve"
        >
          Resolve
        </button>
      </div>
      <pre
        v-if="resolveResult"
        class="overflow-x-auto rounded-md border border-[var(--line)] bg-[var(--panel)] p-3 font-mono text-xs"
      >{{ resolveResult }}</pre>
    </section>

    <p
      v-if="!canManagePricing && !canManagePromos && !canResolve"
      class="text-sm text-[var(--muted)]"
    >
      You do not have pricing permissions.
    </p>
  </div>
</template>
