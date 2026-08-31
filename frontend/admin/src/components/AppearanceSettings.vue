<script setup lang="ts">
import {
  appearanceModeOptions,
  sidebarDarkColorOptions,
  sidebarLightColorOptions,
  textSizeOptions,
  themeColorOptions,
  useAppearanceStore,
} from '@/stores/appearance'

const appearance = useAppearanceStore()
</script>

<template>
  <section
    class="space-y-6 rounded-xl border border-[var(--line)] bg-[var(--panel)] p-5 shadow-[var(--shadow-sm)]"
    aria-labelledby="appearance-heading"
  >
    <div>
      <h2 id="appearance-heading" class="text-lg font-semibold tracking-tight text-[var(--ink)]">
        Appearance
      </h2>
      <p class="mt-1 text-sm text-[var(--muted)]">
        Customise how the admin console looks. Preferences are saved on this device and apply
        immediately.
      </p>
    </div>

    <div class="space-y-2">
      <p class="text-sm font-medium text-[var(--ink)]">Theme colour</p>
      <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Theme colour">
        <button
          v-for="option in themeColorOptions"
          :key="option.id"
          type="button"
          role="radio"
          :aria-checked="appearance.themeColor === option.id"
          class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]"
          :class="
            appearance.themeColor === option.id
              ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand-ink)]'
              : 'border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] hover:border-[var(--brand)]/40'
          "
          @click="appearance.setThemeColor(option.id)"
        >
          <span
            class="h-4 w-4 shrink-0 rounded-full border border-black/10"
            :style="{ backgroundColor: option.swatch }"
            aria-hidden="true"
          />
          {{ option.label }}
        </button>
      </div>
    </div>

    <div class="space-y-4">
      <p class="text-sm font-medium text-[var(--ink)]">Sidebar colour</p>
      <p class="text-xs text-[var(--muted)]">Background colour for the navigation sidebar.</p>

      <div class="space-y-2">
        <p class="text-xs font-semibold tracking-wide text-[var(--muted)] uppercase">Dark</p>
        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Dark sidebar colours">
          <button
            v-for="option in sidebarDarkColorOptions"
            :key="option.id"
            type="button"
            role="radio"
            :aria-checked="appearance.sidebarColor === option.id"
            class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]"
            :class="
              appearance.sidebarColor === option.id
                ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand-ink)]'
                : 'border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] hover:border-[var(--brand)]/40'
            "
            @click="appearance.setSidebarColor(option.id)"
          >
            <span
              class="h-4 w-4 shrink-0 rounded-md border border-black/10"
              :style="{ backgroundColor: option.swatch }"
              aria-hidden="true"
            />
            {{ option.label }}
          </button>
        </div>
      </div>

      <div class="space-y-2">
        <p class="text-xs font-semibold tracking-wide text-[var(--muted)] uppercase">Light</p>
        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Light sidebar colours">
          <button
            v-for="option in sidebarLightColorOptions"
            :key="option.id"
            type="button"
            role="radio"
            :aria-checked="appearance.sidebarColor === option.id"
            class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]"
            :class="
              appearance.sidebarColor === option.id
                ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand-ink)]'
                : 'border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] hover:border-[var(--brand)]/40'
            "
            @click="appearance.setSidebarColor(option.id)"
          >
            <span
              class="h-4 w-4 shrink-0 rounded-md border border-black/10"
              :style="{ backgroundColor: option.swatch }"
              aria-hidden="true"
            />
            {{ option.label }}
          </button>
        </div>
      </div>
    </div>

    <div class="space-y-2">
      <p class="text-sm font-medium text-[var(--ink)]">Appearance</p>
      <div class="inline-flex flex-wrap gap-1 rounded-lg border border-[var(--line)] p-1" role="radiogroup" aria-label="Appearance mode">
        <button
          v-for="option in appearanceModeOptions"
          :key="option.id"
          type="button"
          role="radio"
          :aria-checked="appearance.appearanceMode === option.id"
          class="rounded-md px-3 py-1.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]"
          :class="
            appearance.appearanceMode === option.id
              ? 'bg-[var(--brand)] font-medium text-white'
              : 'text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--ink)]'
          "
          @click="appearance.setAppearanceMode(option.id)"
        >
          {{ option.label }}
        </button>
      </div>
    </div>

    <div class="space-y-2">
      <p class="text-sm font-medium text-[var(--ink)]">Text size</p>
      <div class="inline-flex flex-wrap gap-1 rounded-lg border border-[var(--line)] p-1" role="radiogroup" aria-label="Text size">
        <button
          v-for="option in textSizeOptions"
          :key="option.id"
          type="button"
          role="radio"
          :aria-checked="appearance.textSize === option.id"
          class="rounded-md px-3 py-1.5 text-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]"
          :class="
            appearance.textSize === option.id
              ? 'bg-[var(--brand)] font-medium text-white'
              : 'text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--ink)]'
          "
          @click="appearance.setTextSize(option.id)"
        >
          {{ option.label }}
        </button>
      </div>
    </div>
  </section>
</template>
