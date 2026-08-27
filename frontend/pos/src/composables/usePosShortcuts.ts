import { onMounted, onUnmounted } from 'vue'
import { usePosStore } from '@/stores/pos'

/** Global POS shortcuts when the floor is open (authenticated + store selected). */
export function usePosShortcuts() {
  const pos = usePosStore()

  function onKeydown(event: KeyboardEvent) {
    if (event.repeat || event.ctrlKey || event.metaKey || event.altKey) {
      return
    }

    const key = event.key

    if (key === 'Escape') {
      if (pos.activeStub) {
        event.preventDefault()
        pos.closeStub()
      }
      return
    }

    if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) {
      // Allow F-keys even from search; Esc handled above.
      if (key !== 'F2' && key !== 'F4' && key !== 'F8' && key !== 'F9') {
        return
      }
    }

    switch (key) {
      case 'F2':
        event.preventDefault()
        if (pos.activeStub) {
          pos.closeStub()
        }
        document.getElementById('pos-search')?.focus()
        break
      case 'F4':
        event.preventDefault()
        pos.openStub('customer')
        break
      case 'F8':
        event.preventDefault()
        pos.openStub('discount')
        break
      case 'F9':
        event.preventDefault()
        pos.openStub('payment')
        break
      default:
        break
    }
  }

  onMounted(() => {
    window.addEventListener('keydown', onKeydown)
  })

  onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown)
  })
}
