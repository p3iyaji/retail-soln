import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { getStoredStoreId, parsePositiveIntId, setStoredStoreId, TERMINAL_KEY } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

export type StubPanel = 'customer' | 'discount' | 'payment' | null

function readTerminal(): string {
  return localStorage.getItem(TERMINAL_KEY) ?? 'POS-001'
}

export const usePosStore = defineStore('pos', () => {
  const selectedStoreId = ref<number | null>(getStoredStoreId())
  const terminalLabel = ref(readTerminal())
  const activeStub = ref<StubPanel>(null)

  const selectedStore = computed(() => {
    const id = selectedStoreId.value
    if (id == null) return null
    return useAuthStore().storeAccess.find((s) => s.id === id) ?? null
  })

  const hasSelectedStore = computed(() => selectedStore.value != null)

  function selectStore(storeId: number): void {
    const id = parsePositiveIntId(storeId)
    if (id == null) return
    const allowed = useAuthStore().storeAccess.some((s) => s.id === id)
    if (!allowed) return
    selectedStoreId.value = id
    setStoredStoreId(id)
  }

  function clearStore(): void {
    selectedStoreId.value = null
    setStoredStoreId(null)
  }

  function setTerminalLabel(label: string): void {
    const trimmed = label.trim() || 'POS-001'
    terminalLabel.value = trimmed
    localStorage.setItem(TERMINAL_KEY, trimmed)
  }

  function openStub(panel: Exclude<StubPanel, null>): void {
    activeStub.value = panel
  }

  function closeStub(): void {
    activeStub.value = null
  }

  function syncSelectedStoreWithAccess(): void {
    const id = selectedStoreId.value
    if (id == null) return
    if (!Number.isInteger(id) || id <= 0) {
      clearStore()
      return
    }
    const stillValid = useAuthStore().storeAccess.some((s) => s.id === id)
    if (!stillValid) {
      clearStore()
    }
  }

  return {
    selectedStoreId,
    terminalLabel,
    activeStub,
    selectedStore,
    hasSelectedStore,
    selectStore,
    clearStore,
    setTerminalLabel,
    openStub,
    closeStub,
    syncSelectedStoreWithAccess,
  }
})
