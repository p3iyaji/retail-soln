<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'

type Lookup = { id: number; name: string; abbreviation?: string }
type ProductImage = { url: string; altText?: string; sortOrder?: number; isPrimary?: boolean }
type ProductVariant = {
  id: number
  name: string
  sku: string
  barcode: string | null
  price: string
  costPrice: string | null
  isDefault: boolean
  isActive: boolean
  images?: ProductImage[]
}

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
  sellingPrice: string
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
  variants?: ProductVariant[]
}

const products = ref<Product[]>([])
const variants = ref<ProductVariant[]>([])
const categories = ref<Lookup[]>([])
const brands = ref<Lookup[]>([])
const units = ref<Lookup[]>([])
const suppliers = ref<Lookup[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)
const showForm = ref(false)
const showVariantForm = ref(false)
const editingVariantId = ref<number | null>(null)
const variantSaving = ref(false)

const form = reactive({
  name: '',
  sku: '',
  barcode: '',
  description: '',
  categoryId: '' as string | number,
  brandId: '' as string | number,
  unitId: '' as string | number,
  supplierId: '' as string | number,
  sellingPrice: '0.0000',
  costPrice: '',
  reorderLevel: '',
  minStock: '',
  maxStock: '',
  imageUrl: '',
  isActive: true,
})

const variantForm = reactive({
  name: '',
  sku: '',
  barcode: '',
  price: '0.0000',
  costPrice: '',
  imageUrl: '',
  isDefault: false,
  isActive: true,
})

const formTitle = computed(() => (editingId.value ? 'Edit product' : 'New product'))
const searchQuery = ref('')
const importMessage = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

async function loadLookups() {
  const [cats, brs, uns, sups] = await Promise.all([
    api.get<{ data: Lookup[] }>('/categories'),
    api.get<{ data: Lookup[] }>('/brands'),
    api.get<{ data: Array<Lookup & { abbreviation: string }> }>('/units'),
    api.get<{ data: Lookup[] }>('/suppliers?lite=1'),
  ])
  categories.value = cats.data
  brands.value = brs.data
  units.value = uns.data
  suppliers.value = sups.data
}

async function loadProducts() {
  const q = searchQuery.value.trim()
  if (q) {
    const res = await api.get<{ data: Product[] }>(`/products/search?q=${encodeURIComponent(q)}&limit=50`)
    products.value = res.data
    return
  }
  const res = await api.get<{ data: Product[] }>('/products?per_page=50')
  products.value = res.data
}

async function onSearch() {
  error.value = ''
  try {
    await loadProducts()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Search failed'
  }
}

async function onExport() {
  error.value = ''
  try {
    await api.download('/products/export', 'products-export.csv')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Export failed'
  }
}

async function onImportFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  error.value = ''
  importMessage.value = ''
  try {
    const formData = new FormData()
    formData.append('file', file)
    const preview = await api.upload<{
      data: { totalRows: number; validCount: number; errorCount: number; errors: Array<{ row: number; message: string }> }
    }>('/products/import/preview', formData)

    if (preview.data.errorCount > 0 && preview.data.validCount === 0) {
      error.value = `Import preview failed: ${preview.data.errors[0]?.message ?? 'validation errors'}`
      return
    }

    const importForm = new FormData()
    importForm.append('file', file)
    const result = await api.upload<{
      data: { id: number; status: string; successCount: number; errorCount: number; async: boolean }
    }>('/products/import', importForm)

    importMessage.value = result.data.async
      ? `Import #${result.data.id} queued (${result.data.status}).`
      : `Imported ${result.data.successCount} row(s); ${result.data.errorCount} error(s).`
    await loadProducts()
    await loadLookups()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Import failed'
  } finally {
    input.value = ''
  }
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
  form.sellingPrice = '0.0000'
  form.costPrice = ''
  form.reorderLevel = ''
  form.minStock = ''
  form.maxStock = ''
  form.imageUrl = ''
  form.isActive = true
  variants.value = []
  resetVariantForm()
}

function resetVariantForm() {
  editingVariantId.value = null
  showVariantForm.value = false
  variantForm.name = ''
  variantForm.sku = ''
  variantForm.barcode = ''
  variantForm.price = '0.0000'
  variantForm.costPrice = ''
  variantForm.imageUrl = ''
  variantForm.isDefault = false
  variantForm.isActive = true
}

async function loadVariants(productId: number) {
  const res = await api.get<{ data: ProductVariant[] }>(`/products/${productId}/variants`)
  variants.value = res.data
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
  form.sellingPrice = product.sellingPrice
  form.costPrice = product.costPrice ?? ''
  form.reorderLevel = product.reorderLevel ?? ''
  form.minStock = product.minStock ?? ''
  form.maxStock = product.maxStock ?? ''
  form.imageUrl = product.images?.[0]?.url ?? ''
  form.isActive = product.isActive
  showForm.value = true
  resetVariantForm()
  void loadVariants(product.id).catch((e) => {
    error.value = e instanceof ApiError ? e.message : 'Failed to load variants'
  })
}

function openVariantCreate() {
  resetVariantForm()
  variantForm.name = 'Default'
  variantForm.sku = form.sku
  variantForm.barcode = form.barcode
  variantForm.price = form.sellingPrice || '0.0000'
  variantForm.costPrice = form.costPrice
  variantForm.isDefault = variants.value.length === 0
  showVariantForm.value = true
}

function openVariantEdit(variant: ProductVariant) {
  editingVariantId.value = variant.id
  variantForm.name = variant.name
  variantForm.sku = variant.sku
  variantForm.barcode = variant.barcode ?? ''
  variantForm.price = variant.price
  variantForm.costPrice = variant.costPrice ?? ''
  variantForm.imageUrl = variant.images?.[0]?.url ?? ''
  variantForm.isDefault = variant.isDefault
  variantForm.isActive = variant.isActive
  showVariantForm.value = true
}

async function onVariantSubmit() {
  if (!editingId.value) return
  variantSaving.value = true
  error.value = ''
  try {
    const images = variantForm.imageUrl
      ? [{ url: variantForm.imageUrl, altText: variantForm.name, sortOrder: 0, isPrimary: true }]
      : []
    const body = {
      name: variantForm.name,
      sku: variantForm.sku,
      barcode: variantForm.barcode || null,
      price: variantForm.price,
      costPrice: variantForm.costPrice || null,
      isDefault: variantForm.isDefault,
      isActive: variantForm.isActive,
      images,
    }
    if (editingVariantId.value) {
      await api.patch(`/products/${editingId.value}/variants/${editingVariantId.value}`, body)
    } else {
      await api.post(`/products/${editingId.value}/variants`, body)
    }
    resetVariantForm()
    await loadVariants(editingId.value)
    await loadProducts()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Variant save failed'
  } finally {
    variantSaving.value = false
  }
}

async function onVariantDelete(variant: ProductVariant) {
  if (!editingId.value || !confirm(`Delete variant ${variant.name}?`)) return
  error.value = ''
  try {
    await api.delete(`/products/${editingId.value}/variants/${variant.id}`)
    await loadVariants(editingId.value)
    await loadProducts()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Variant delete failed'
  }
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
    sellingPrice: form.sellingPrice,
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
          Catalog CRUD with search, CSV import/export, variants, and stock thresholds.
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
          @click="onExport"
        >
          Export CSV
        </button>
        <button
          type="button"
          class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
          @click="fileInput?.click()"
        >
          Import CSV
        </button>
        <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" @change="onImportFile" />
        <button
          type="button"
          class="rounded-md bg-[var(--brand)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
          @click="openCreate"
        >
          New product
        </button>
      </div>
    </div>

    <form class="flex flex-wrap gap-2" @submit.prevent="onSearch">
      <input
        v-model="searchQuery"
        type="search"
        placeholder="Search SKU, barcode, name, category…"
        class="min-w-[240px] flex-1 rounded-md border border-[var(--line)] px-3 py-2 text-sm"
      />
      <button type="submit" class="rounded-md border border-[var(--line)] px-3 py-2 text-sm">Search</button>
      <button
        v-if="searchQuery"
        type="button"
        class="rounded-md border border-[var(--line)] px-3 py-2 text-sm"
        @click="searchQuery = ''; onSearch()"
      >
        Clear
      </button>
    </form>

    <p v-if="importMessage" class="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900">
      {{ importMessage }}
    </p>

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
          <span class="text-[var(--muted)]">Selling price</span>
          <input v-model="form.sellingPrice" required class="mt-1 w-full rounded-md border border-[var(--line)] px-3 py-2" />
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

      <div v-if="editingId" class="mt-6 border-t border-[var(--line)] pt-4">
        <div class="flex items-center justify-between gap-3">
          <h4 class="text-sm font-semibold">Variants</h4>
          <button
            type="button"
            class="rounded-md border border-[var(--line)] px-2 py-1 text-xs"
            @click="openVariantCreate"
          >
            Add variant
          </button>
        </div>

        <form v-if="showVariantForm" class="mt-3 grid gap-2 sm:grid-cols-3" @submit.prevent="onVariantSubmit">
          <input v-model="variantForm.name" required placeholder="Name" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
          <input v-model="variantForm.sku" required placeholder="SKU" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm font-mono" />
          <input v-model="variantForm.barcode" placeholder="Barcode" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm font-mono" />
          <input v-model="variantForm.price" required placeholder="Selling price" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
          <input v-model="variantForm.costPrice" placeholder="Cost" class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm" />
          <input
            v-model="variantForm.imageUrl"
            placeholder="Image URL"
            class="rounded-md border border-[var(--line)] px-2 py-1.5 text-sm sm:col-span-2"
          />
          <label class="flex items-center gap-2 text-sm">
            <input v-model="variantForm.isDefault" type="checkbox" />
            Default variant
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="variantForm.isActive" type="checkbox" />
            Active
          </label>
          <div class="flex gap-2 sm:col-span-3">
            <button type="submit" class="rounded-md bg-[var(--brand)] px-2 py-1 text-xs text-white disabled:opacity-60" :disabled="variantSaving">
              {{ variantSaving ? 'Saving…' : editingVariantId ? 'Update variant' : 'Add variant' }}
            </button>
            <button type="button" class="rounded-md border border-[var(--line)] px-2 py-1 text-xs" @click="resetVariantForm">Cancel</button>
          </div>
        </form>

        <table v-if="variants.length" class="mt-3 min-w-full text-left text-sm">
          <thead class="text-[var(--muted)]">
            <tr>
              <th class="py-1 pr-3 font-medium">Image</th>
              <th class="py-1 pr-3 font-medium">Name</th>
              <th class="py-1 pr-3 font-medium">SKU</th>
              <th class="py-1 pr-3 font-medium">Barcode</th>
              <th class="py-1 pr-3 font-medium">Selling price</th>
              <th class="py-1 pr-3 font-medium">Status</th>
              <th class="py-1 font-medium" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in variants" :key="v.id" class="border-t border-[var(--line)]">
              <td class="py-2 pr-3">
                <img
                  v-if="v.images?.[0]?.url"
                  :src="v.images[0].url"
                  :alt="v.name"
                  class="h-8 w-8 rounded object-cover"
                />
                <span v-else class="text-[var(--muted)]">—</span>
              </td>
              <td class="py-2 pr-3">{{ v.name }}{{ v.isDefault ? ' (default)' : '' }}</td>
              <td class="py-2 pr-3 font-mono text-xs">{{ v.sku }}</td>
              <td class="py-2 pr-3 font-mono text-xs">{{ v.barcode ?? '—' }}</td>
              <td class="py-2 pr-3">{{ v.price }}</td>
              <td class="py-2 pr-3">{{ v.isActive ? 'Active' : 'Inactive' }}</td>
              <td class="py-2 text-right">
                <button type="button" class="mr-2 text-[var(--brand)]" @click="openVariantEdit(v)">Edit</button>
                <button type="button" class="text-red-700" @click="onVariantDelete(v)">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="mt-2 text-sm text-[var(--muted)]">No extra variants — a default sellable SKU is created automatically when you save the product.</p>
      </div>
    </div>

    <div v-if="!loading" class="overflow-x-auto rounded-lg border border-[var(--line)]">
      <table class="min-w-full text-left text-sm">
        <thead class="border-b border-[var(--line)] bg-stone-50 text-[var(--muted)]">
          <tr>
            <th class="px-3 py-2 font-medium">Name</th>
            <th class="px-3 py-2 font-medium">SKU</th>
            <th class="px-3 py-2 font-medium">Category</th>
            <th class="px-3 py-2 font-medium">Selling price</th>
            <th class="px-3 py-2 font-medium">Variants</th>
            <th class="px-3 py-2 font-medium">Reorder</th>
            <th class="px-3 py-2 font-medium" />
          </tr>
        </thead>
        <tbody>
          <tr v-if="products.length === 0">
            <td colspan="7" class="px-3 py-6 text-[var(--muted)]">No products yet.</td>
          </tr>
          <tr v-for="p in products" :key="p.id" class="border-b border-[var(--line)] last:border-0">
            <td class="px-3 py-2">{{ p.name }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ p.sku }}</td>
            <td class="px-3 py-2">{{ p.category?.name ?? '—' }}</td>
            <td class="px-3 py-2">{{ p.sellingPrice }}</td>
            <td class="px-3 py-2">{{ p.variants?.length ?? 0 }}</td>
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
