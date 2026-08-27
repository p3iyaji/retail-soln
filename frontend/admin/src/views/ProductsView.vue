<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'

type Lookup = { id: number; name: string; abbreviation?: string }
type ProductImage = { url: string; altText?: string; sortOrder?: number; isPrimary?: boolean }
type Product = {
  id: number
  name: string
  sku: string
  barcode: string | null
  description: string | null
  categoryId: number | null
  brandId: number | null
  unitId: number | null
  supplierId: number | null
  basePrice: string
  costPrice: string | null
  reorderLevel: string | null
  minStock: string | null
  maxStock: string | null
  isActive: boolean
  images: ProductImage[]
  category?: Lookup | null
  brand?: Lookup | null
  unit?: Lookup | null
  supplier?: Lookup | null
}

const products = ref<Product[]>([])
const categories = ref<Lookup[]>([])
const brands = ref<Lookup[]>([])
const units = ref<Lookup[]>([])
const suppliers = ref<Lookup[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)
const showForm = ref(false)

const form = reactive({
  name: '',
  sku: '',
  barcode: '',
  description: '',
  categoryId: '' as string | number,
  brandId: '' as string | number,
  unitId: '' as string | number,
  supplierId: '' as string | number,
  basePrice: '0.0000',
  costPrice: '',
  reorderLevel: '',
  minStock: '',
  maxStock: '',
  imageUrl: '',
  isActive: true,
})

const formTitle = computed(() => (editingId.value ? 'Edit product' : 'New product'))

async function loadLookups() {
  const [cats, brs, uns, sups] = await Promise.all([
    api.get<{ data: Lookup[] }>('/categories'),
    api.get<{ data: Lookup[] }>('/brands'),
    api.get<{ data: Array<Lookup & { abbreviation: string }> }>('/units'),
    api.get<{ data: Lookup[] }>('/suppliers'),
  ])
  categories.value = cats.data
  brands.value = brs.data
  units.value = uns.data
  suppliers.value = sups.data
}

async function loadProducts() {
  const res = await api.get<{ data: Product[] }>('/products?per_page=50')
  products.value = res.data
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadLookups(), loadProducts()])
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Failed to load catalog'
  } finally {
    loading.value = false
  }
}

function resetForm() {
  editingId.value = null
  form.name = ''
  form.sku = ''
  form.barcode = ''
  form.description = ''
  form.categoryId = ''
  form.brandId = ''
  form.unitId = ''
  form.supplierId = ''
  form.basePrice = '0.0000'
  form.costPrice = ''
  form.reorderLevel = ''
  form.minStock = ''
  form.maxStock = ''
  form.imageUrl = ''
  form.isActive = true
}

function openCreate() {
  resetForm()
  showForm.value = true
}

function openEdit(product: Product) {
  editingId.value = product.id
  form.name = product.name
  form.sku = product.sku
  form.barcode = product.barcode ?? ''
  form.description = product.description ?? ''
  form.categoryId = product.categoryId ?? ''
  form.brandId = product.brandId ?? ''
  form.unitId = product.unitId ?? ''
  form.supplierId = product.supplierId ?? ''
  form.basePrice = product.basePrice
  form.costPrice = product.costPrice ?? ''
  form.reorderLevel = product.reorderLevel ?? ''
  form.minStock = product.minStock ?? ''
  form.maxStock = product.maxStock ?? ''
  form.imageUrl = product.images?.[0]?.url ?? ''
  form.isActive = product.isActive
  showForm.value = true
}

function payload() {
  const images = form.imageUrl
    ? [{ url: form.imageUrl, altText: form.name, sortOrder: 0, isPrimary: true }]
    : []

  return {
    name: form.name,
    sku: form.sku,
    barcode: form.barcode || null,
    description: form.description || null,
    categoryId: form.categoryId === '' ? null : Number(form.categoryId),
    brandId: form.brandId === '' ? null : Number(form.brandId),
    unitId: form.unitId === '' ? null : Number(form.unitId),
    supplierId: form.supplierId === '' ? null : Number(form.supplierId),
    basePrice: form.basePrice,
    costPrice: form.costPrice || null,
    reorderLevel: form.reorderLevel || null,
    minStock: form.minStock || null,
    maxStock: form.maxStock || null,
    isActive: form.isActive,
    images,
  }
}

async function onSubmit() {
  saving.value = true
  error.value = ''
  try {
    if (editingId.value) {
      await api.patch(`/products/${editingId.value}`, payload())
    } else {
      await api.post('/products', payload())
    }
    showForm.value = false
    resetForm()
    await loadProducts()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Save failed'
  } finally {
    saving.value = false
  }
}

async function onDelete(product: Product) {
  if (!confirm(`Delete ${product.name}?`)) return
  error.value = ''
  try {
    await api.delete(`/products/${product.id}`)
    await loadProducts()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Delete failed'
  }
}

async function quickCreate(kind: 'categories' | 'brands' | 'units' | 'suppliers') {
  const name = prompt(`New ${kind.slice(0, -1)} name`)
  if (!name) return

  try {
    if (kind === 'units') {
      const abbreviation = prompt('Abbreviation (e.g. ea)') ?? 'ea'
      await api.post('/units', { name, abbreviation })
    } else if (kind === 'suppliers') {
      await api.post('/suppliers', { name })
    } else if (kind === 'categories') {
      await api.post('/categories', { name })
    } else {
      await api.post('/brands', { name })
    }
    await loadLookups()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Lookup create failed'
  }
}

onMounted(refresh)
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Products</h2>
        <p class="mt-1 text-sm text-[var(--muted)]">
          Catalog CRUD with SKU, barcode, category, brand, supplier, unit, images, and stock thresholds.
        </p>
      </div>
      <button
        type="button"
        class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
        @click="openCreate"
      >
        New product
      </button>
    </div>

    <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
      {{ error }}
    </p>
    <p v-if="loading" class="text-sm text-[var(--muted)]">Loading catalog…</p>

    <div v-if="showForm" class="rounded-lg border border-[var(--line)] bg-[var(--panel)] p-4">
      <h3 class="text-base font-semibold">{{ formTitle }}</h3>
      <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="onSubmit">
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Name</span>
          <input v-model="form.name" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">SKU</span>
          <input v-model="form.sku" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Barcode</span>
          <input v-model="form.barcode" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Base price</span>
          <input v-model="form.basePrice" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Cost price</span>
          <input v-model="form.costPrice" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Image URL</span>
          <input v-model="form.imageUrl" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>

        <label class="block text-sm">
          <span class="flex items-center justify-between text-[var(--muted)]">
            Category
            <button type="button" class="text-xs text-[var(--brand)]" @click="quickCreate('categories')">+ add</button>
          </span>
          <select v-model="form.categoryId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option value="">—</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="flex items-center justify-between text-[var(--muted)]">
            Brand
            <button type="button" class="text-xs text-[var(--brand)]" @click="quickCreate('brands')">+ add</button>
          </span>
          <select v-model="form.brandId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option value="">—</option>
            <option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="flex items-center justify-between text-[var(--muted)]">
            Unit
            <button type="button" class="text-xs text-[var(--brand)]" @click="quickCreate('units')">+ add</button>
          </span>
          <select v-model="form.unitId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option value="">—</option>
            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }} ({{ u.abbreviation }})</option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="flex items-center justify-between text-[var(--muted)]">
            Supplier
            <button type="button" class="text-xs text-[var(--brand)]" @click="quickCreate('suppliers')">+ add</button>
          </span>
          <select v-model="form.supplierId" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2">
            <option value="">—</option>
            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>

        <label class="block text-sm">
          <span class="text-[var(--muted)]">Reorder level</span>
          <input v-model="form.reorderLevel" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Min stock</span>
          <input v-model="form.minStock" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm">
          <span class="text-[var(--muted)]">Max stock</span>
          <input v-model="form.maxStock" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>
        <label class="block text-sm sm:col-span-2">
          <span class="text-[var(--muted)]">Description</span>
          <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
        </label>

        <div class="flex gap-2 sm:col-span-2">
          <button
            type="submit"
            class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white disabled:opacity-60"
            :disabled="saving"
          >
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
          <button
            type="button"
            class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
            @click="showForm = false"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>

    <div v-if="!loading" class="overflow-x-auto rounded-lg border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">SKU</th>
            <th class="px-3 py-2 font-medium">Category</th>
            <th class="px-3 py-2 font-medium">Price</th>
            <th class="px-3 py-2 font-medium">Reorder</th>
            <th class="px-3 py-2 font-medium" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="products.length === 0">
            <td colspan="6" class="px-3 py-6 text-[var(--muted)]">No products yet.</td>
          </tr>
          <tr v-for="p in products" :key="p.id" class="border-b border-[var(--line)] last:border-0">
            <td class="px-3 py-2">{{ p.name }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ p.sku }}</td>
            <td class="px-3 py-2">{{ p.category?.name ?? '—' }}</td>
            <td class="px-3 py-2">{{ p.basePrice }}</td>
            <td class="px-3 py-2">{{ p.reorderLevel ?? '—' }}</td>
            <td class="px-3 py-2 text-right">
              <button type="button" class="mr-2 text-[var(--brand)]" @click="openEdit(p)">Edit</button>
              <button type="button" class="text-red-700" @click="onDelete(p)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
