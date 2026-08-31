import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

export type ThemeColor = 'teal' | 'blue' | 'indigo' | 'violet' | 'emerald' | 'slate'
export type SidebarColor = 'slate' | 'navy' | 'charcoal' | 'forest' | 'plum' | 'wine' | 'light'
export type AppearanceMode = 'light' | 'dark' | 'system'
export type TextSize = 'sm' | 'md' | 'lg'

type AppearanceState = {
  themeColor: ThemeColor
  sidebarColor: SidebarColor
  appearanceMode: AppearanceMode
  textSize: TextSize
  openNavGroups: string[]
}

const STORAGE_PREFIX = 'retail_soln_admin_appearance'
const DEFAULT_STATE: AppearanceState = {
  themeColor: 'teal',
  sidebarColor: 'slate',
  appearanceMode: 'system',
  textSize: 'md',
  openNavGroups: [],
}

export const themeColorOptions: Array<{ id: ThemeColor; label: string; swatch: string }> = [
  { id: 'teal', label: 'Teal', swatch: '#0d9488' },
  { id: 'blue', label: 'Blue', swatch: '#2563eb' },
  { id: 'indigo', label: 'Indigo', swatch: '#4f46e5' },
  { id: 'violet', label: 'Violet', swatch: '#7c3aed' },
  { id: 'emerald', label: 'Emerald', swatch: '#059669' },
  { id: 'slate', label: 'Slate', swatch: '#475569' },
]

export const sidebarColorOptions: Array<{ id: SidebarColor; label: string; swatch: string }> = [
  { id: 'slate', label: 'Slate', swatch: '#0f172a' },
  { id: 'navy', label: 'Navy', swatch: '#0a1628' },
  { id: 'charcoal', label: 'Charcoal', swatch: '#18181b' },
  { id: 'forest', label: 'Forest', swatch: '#052e16' },
  { id: 'plum', label: 'Plum', swatch: '#2e1065' },
  { id: 'wine', label: 'Wine', swatch: '#450a0a' },
  { id: 'light', label: 'Light', swatch: '#f8fafc' },
]

export const appearanceModeOptions: Array<{ id: AppearanceMode; label: string }> = [
  { id: 'light', label: 'Light' },
  { id: 'dark', label: 'Dark' },
  { id: 'system', label: 'System' },
]

export const textSizeOptions: Array<{ id: TextSize; label: string }> = [
  { id: 'sm', label: 'Small' },
  { id: 'md', label: 'Medium' },
  { id: 'lg', label: 'Large' },
]

function storageKey(userId?: number | null): string {
  return userId ? `${STORAGE_PREFIX}_${userId}` : STORAGE_PREFIX
}

function readState(userId?: number | null): AppearanceState {
  try {
    const raw = localStorage.getItem(storageKey(userId))
    if (!raw) return { ...DEFAULT_STATE }
    const parsed = JSON.parse(raw) as Partial<AppearanceState>
    return {
      themeColor: themeColorOptions.some((o) => o.id === parsed.themeColor)
        ? (parsed.themeColor as ThemeColor)
        : DEFAULT_STATE.themeColor,
      sidebarColor: sidebarColorOptions.some((o) => o.id === parsed.sidebarColor)
        ? (parsed.sidebarColor as SidebarColor)
        : DEFAULT_STATE.sidebarColor,
      appearanceMode: appearanceModeOptions.some((o) => o.id === parsed.appearanceMode)
        ? (parsed.appearanceMode as AppearanceMode)
        : DEFAULT_STATE.appearanceMode,
      textSize: textSizeOptions.some((o) => o.id === parsed.textSize)
        ? (parsed.textSize as TextSize)
        : DEFAULT_STATE.textSize,
      openNavGroups: Array.isArray(parsed.openNavGroups)
        ? parsed.openNavGroups.filter((g): g is string => typeof g === 'string')
        : DEFAULT_STATE.openNavGroups,
    }
  } catch {
    return { ...DEFAULT_STATE }
  }
}

function writeState(state: AppearanceState, userId?: number | null): void {
  localStorage.setItem(storageKey(userId), JSON.stringify(state))
}

function resolveDark(mode: AppearanceMode): boolean {
  if (mode === 'dark') return true
  if (mode === 'light') return false
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function applyToDocument(state: AppearanceState): void {
  const root = document.documentElement
  root.dataset.theme = state.themeColor
  root.dataset.sidebar = state.sidebarColor
  root.dataset.textSize = state.textSize
  root.classList.toggle('dark', resolveDark(state.appearanceMode))
  root.style.colorScheme = resolveDark(state.appearanceMode) ? 'dark' : 'light'
}

let systemListener: ((event: MediaQueryListEvent) => void) | null = null

export const useAppearanceStore = defineStore('appearance', () => {
  const themeColor = ref<ThemeColor>(DEFAULT_STATE.themeColor)
  const sidebarColor = ref<SidebarColor>(DEFAULT_STATE.sidebarColor)
  const appearanceMode = ref<AppearanceMode>(DEFAULT_STATE.appearanceMode)
  const textSize = ref<TextSize>(DEFAULT_STATE.textSize)
  const openNavGroups = ref<string[]>(DEFAULT_STATE.openNavGroups)
  const activeUserId = ref<number | null>(null)

  function snapshot(): AppearanceState {
    return {
      themeColor: themeColor.value,
      sidebarColor: sidebarColor.value,
      appearanceMode: appearanceMode.value,
      textSize: textSize.value,
      openNavGroups: [...openNavGroups.value],
    }
  }

  function persist(): void {
    writeState(snapshot(), activeUserId.value)
  }

  function apply(): void {
    applyToDocument(snapshot())
  }

  function load(userId?: number | null): void {
    activeUserId.value = userId ?? null
    if (userId && !localStorage.getItem(storageKey(userId))) {
      writeState(readState(null), userId)
    }
    const state = readState(activeUserId.value)
    themeColor.value = state.themeColor
    sidebarColor.value = state.sidebarColor
    appearanceMode.value = state.appearanceMode
    textSize.value = state.textSize
    openNavGroups.value = state.openNavGroups
    apply()
  }

  function init(userId?: number | null): void {
    load(userId)
    attachSystemListener()
  }

  function attachSystemListener(): void {
    if (systemListener) return
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    systemListener = () => {
      if (appearanceMode.value === 'system') apply()
    }
    media.addEventListener('change', systemListener)
  }

  function setThemeColor(value: ThemeColor): void {
    themeColor.value = value
    persist()
    apply()
  }

  function setSidebarColor(value: SidebarColor): void {
    sidebarColor.value = value
    persist()
    apply()
  }

  function setAppearanceMode(value: AppearanceMode): void {
    appearanceMode.value = value
    persist()
    apply()
  }

  function setTextSize(value: TextSize): void {
    textSize.value = value
    persist()
    apply()
  }

  function setOpenNavGroups(groups: string[]): void {
    openNavGroups.value = [...groups]
    persist()
  }

  function toggleNavGroup(groupId: string): void {
    const next = new Set(openNavGroups.value)
    if (next.has(groupId)) {
      next.delete(groupId)
    } else {
      next.add(groupId)
    }
    setOpenNavGroups([...next])
  }

  function ensureNavGroupOpen(groupId: string): void {
    if (openNavGroups.value.includes(groupId)) return
    setOpenNavGroups([...openNavGroups.value, groupId])
  }

  function isNavGroupOpen(groupId: string): boolean {
    return openNavGroups.value.includes(groupId)
  }

  watch([themeColor, sidebarColor, appearanceMode, textSize], () => {
    apply()
  })

  return {
    themeColor,
    sidebarColor,
    appearanceMode,
    textSize,
    openNavGroups,
    init,
    load,
    apply,
    persist,
    setThemeColor,
    setSidebarColor,
    setAppearanceMode,
    setTextSize,
    setOpenNavGroups,
    toggleNavGroup,
    ensureNavGroupOpen,
    isNavGroupOpen,
  }
})
