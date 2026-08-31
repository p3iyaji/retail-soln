export type SidebarPaletteTokens = {
  bg: string
  border: string
  text: string
  muted: string
  heading: string
  hoverBg: string
}

export type SidebarPaletteDef = SidebarPaletteTokens & {
  label: string
  swatch: string
  darkMode?: SidebarPaletteTokens
}

export const sidebarPalettes = {
  slate: {
    label: 'Slate',
    swatch: '#0f172a',
    bg: '#0f172a',
    border: '#1e293b',
    text: '#cbd5e1',
    muted: '#64748b',
    heading: '#ffffff',
    hoverBg: 'rgb(255 255 255 / 0.05)',
  },
  navy: {
    label: 'Navy',
    swatch: '#0a1628',
    bg: '#0a1628',
    border: '#152238',
    text: '#cbd5e1',
    muted: '#64748b',
    heading: '#ffffff',
    hoverBg: 'rgb(255 255 255 / 0.05)',
  },
  charcoal: {
    label: 'Charcoal',
    swatch: '#18181b',
    bg: '#18181b',
    border: '#27272a',
    text: '#d4d4d8',
    muted: '#71717a',
    heading: '#ffffff',
    hoverBg: 'rgb(255 255 255 / 0.05)',
  },
  forest: {
    label: 'Forest',
    swatch: '#052e16',
    bg: '#052e16',
    border: '#14532d',
    text: '#bbf7d0',
    muted: '#4ade80',
    heading: '#ecfdf5',
    hoverBg: 'rgb(255 255 255 / 0.05)',
  },
  plum: {
    label: 'Plum',
    swatch: '#2e1065',
    bg: '#2e1065',
    border: '#4c1d95',
    text: '#ddd6fe',
    muted: '#a78bfa',
    heading: '#f5f3ff',
    hoverBg: 'rgb(255 255 255 / 0.05)',
  },
  wine: {
    label: 'Wine',
    swatch: '#450a0a',
    bg: '#450a0a',
    border: '#7f1d1d',
    text: '#fecaca',
    muted: '#f87171',
    heading: '#fff1f2',
    hoverBg: 'rgb(255 255 255 / 0.05)',
  },
  light: {
    label: 'Light',
    swatch: '#f8fafc',
    bg: '#ffffff',
    border: '#e2e8e6',
    text: '#475569',
    muted: '#94a3b8',
    heading: '#0f172a',
    hoverBg: 'rgb(15 23 42 / 0.05)',
    darkMode: {
      bg: '#1e293b',
      border: '#334155',
      text: '#cbd5e1',
      muted: '#64748b',
      heading: '#f8fafc',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  stone: {
    label: 'Stone',
    swatch: '#e8e5e1',
    bg: '#e8e5e1',
    border: '#d6d3ce',
    text: '#44403c',
    muted: '#78716c',
    heading: '#1c1917',
    hoverBg: 'rgb(28 25 23 / 0.06)',
    darkMode: {
      bg: '#292524',
      border: '#44403c',
      text: '#e7e5e4',
      muted: '#a8a29e',
      heading: '#fafaf9',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  mist: {
    label: 'Mist',
    swatch: '#e2e8f0',
    bg: '#e2e8f0',
    border: '#cbd5e1',
    text: '#334155',
    muted: '#64748b',
    heading: '#0f172a',
    hoverBg: 'rgb(15 23 42 / 0.06)',
    darkMode: {
      bg: '#1e293b',
      border: '#334155',
      text: '#cbd5e1',
      muted: '#94a3b8',
      heading: '#f8fafc',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  pearl: {
    label: 'Pearl',
    swatch: '#f5f0eb',
    bg: '#f5f0eb',
    border: '#e7e0d8',
    text: '#44403c',
    muted: '#78716c',
    heading: '#1c1917',
    hoverBg: 'rgb(28 25 23 / 0.06)',
    darkMode: {
      bg: '#292524',
      border: '#44403c',
      text: '#e7e5e4',
      muted: '#a8a29e',
      heading: '#fafaf9',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  cloud: {
    label: 'Cloud',
    swatch: '#f1f5f9',
    bg: '#f1f5f9',
    border: '#e2e8f0',
    text: '#334155',
    muted: '#64748b',
    heading: '#0f172a',
    hoverBg: 'rgb(15 23 42 / 0.06)',
    darkMode: {
      bg: '#1e293b',
      border: '#334155',
      text: '#cbd5e1',
      muted: '#94a3b8',
      heading: '#f8fafc',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  linen: {
    label: 'Linen',
    swatch: '#f0ede8',
    bg: '#f0ede8',
    border: '#e0dcd4',
    text: '#44403c',
    muted: '#78716c',
    heading: '#1c1917',
    hoverBg: 'rgb(28 25 23 / 0.06)',
    darkMode: {
      bg: '#292524',
      border: '#44403c',
      text: '#e7e5e4',
      muted: '#a8a29e',
      heading: '#fafaf9',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  ivory: {
    label: 'Ivory',
    swatch: '#faf6f0',
    bg: '#faf6f0',
    border: '#ebe4d8',
    text: '#44403c',
    muted: '#78716c',
    heading: '#1c1917',
    hoverBg: 'rgb(28 25 23 / 0.06)',
    darkMode: {
      bg: '#292524',
      border: '#44403c',
      text: '#e7e5e4',
      muted: '#a8a29e',
      heading: '#fafaf9',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  frost: {
    label: 'Frost',
    swatch: '#eef2f5',
    bg: '#eef2f5',
    border: '#dce4ea',
    text: '#334155',
    muted: '#64748b',
    heading: '#0f172a',
    hoverBg: 'rgb(15 23 42 / 0.06)',
    darkMode: {
      bg: '#1e293b',
      border: '#334155',
      text: '#cbd5e1',
      muted: '#94a3b8',
      heading: '#f8fafc',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  sage: {
    label: 'Sage',
    swatch: '#d4dcd0',
    bg: '#d4dcd0',
    border: '#bcc4b8',
    text: '#365314',
    muted: '#4d7c0f',
    heading: '#1a2e05',
    hoverBg: 'rgb(26 46 5 / 0.06)',
    darkMode: {
      bg: '#14532d',
      border: '#166534',
      text: '#bbf7d0',
      muted: '#4ade80',
      heading: '#ecfdf5',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  rose: {
    label: 'Rose',
    swatch: '#f4e8e8',
    bg: '#f4e8e8',
    border: '#e8d4d4',
    text: '#57534e',
    muted: '#78716c',
    heading: '#1c1917',
    hoverBg: 'rgb(28 25 23 / 0.06)',
    darkMode: {
      bg: '#4c0519',
      border: '#881337',
      text: '#fecdd3',
      muted: '#fb7185',
      heading: '#fff1f2',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  lavender: {
    label: 'Lavender',
    swatch: '#ede8f0',
    bg: '#ede8f0',
    border: '#dcd4e3',
    text: '#4c1d95',
    muted: '#6d28d9',
    heading: '#2e1065',
    hoverBg: 'rgb(46 16 101 / 0.06)',
    darkMode: {
      bg: '#3b0764',
      border: '#581c87',
      text: '#ddd6fe',
      muted: '#a78bfa',
      heading: '#f5f3ff',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  sky: {
    label: 'Sky',
    swatch: '#e8f0f5',
    bg: '#e8f0f5',
    border: '#d4e2ea',
    text: '#0c4a6e',
    muted: '#0369a1',
    heading: '#082f49',
    hoverBg: 'rgb(8 47 73 / 0.06)',
    darkMode: {
      bg: '#0c4a6e',
      border: '#075985',
      text: '#bae6fd',
      muted: '#38bdf8',
      heading: '#f0f9ff',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
  cream: {
    label: 'Cream',
    swatch: '#fcf9f2',
    bg: '#fcf9f2',
    border: '#f0ead8',
    text: '#44403c',
    muted: '#78716c',
    heading: '#1c1917',
    hoverBg: 'rgb(28 25 23 / 0.06)',
    darkMode: {
      bg: '#292524',
      border: '#44403c',
      text: '#e7e5e4',
      muted: '#a8a29e',
      heading: '#fafaf9',
      hoverBg: 'rgb(255 255 255 / 0.06)',
    },
  },
} as const satisfies Record<string, SidebarPaletteDef>

export type SidebarColor = keyof typeof sidebarPalettes

export const sidebarColorOptions = (Object.keys(sidebarPalettes) as SidebarColor[]).map((id) => ({
  id,
  label: sidebarPalettes[id].label,
  swatch: sidebarPalettes[id].swatch,
}))

export const sidebarDarkColorOptions = sidebarColorOptions.filter((option) =>
  ['slate', 'navy', 'charcoal', 'forest', 'plum', 'wine'].includes(option.id),
)

export const sidebarLightColorOptions = sidebarColorOptions.filter(
  (option) => !sidebarDarkColorOptions.some((dark) => dark.id === option.id),
)

export function resolveSidebarTokens(
  sidebarColor: SidebarColor,
  isDark: boolean,
): SidebarPaletteTokens {
  const palette = sidebarPalettes[sidebarColor]
  const darkMode = 'darkMode' in palette ? palette.darkMode : undefined

  if (isDark && darkMode) {
    return darkMode
  }

  return {
    bg: palette.bg,
    border: palette.border,
    text: palette.text,
    muted: palette.muted,
    heading: palette.heading,
    hoverBg: palette.hoverBg,
  }
}

export function applySidebarTokens(sidebarColor: SidebarColor, isDark: boolean): void {
  const tokens = resolveSidebarTokens(sidebarColor, isDark)
  const root = document.documentElement

  root.style.setProperty('--sidebar-bg', tokens.bg)
  root.style.setProperty('--sidebar-border', tokens.border)
  root.style.setProperty('--sidebar-text', tokens.text)
  root.style.setProperty('--sidebar-muted', tokens.muted)
  root.style.setProperty('--sidebar-heading', tokens.heading)
  root.style.setProperty('--sidebar-hover-bg', tokens.hoverBg)
}
