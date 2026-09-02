<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import {
  ApiError,
  formatPortalMoney,
  hasToken,
  supportApi,
  type PortalChatProductMatch,
  type PortalChatResponse,
} from '@/api/client'

type ChatMessage = {
  id: number
  role: 'user' | 'assistant'
  text: string
  productMatches?: PortalChatProductMatch[]
}

const router = useRouter()

const open = ref(false)
const draft = ref('')
const sending = ref(false)
const error = ref<string | null>(null)
const messages = ref<ChatMessage[]>([])
const transcriptRef = ref<HTMLElement | null>(null)

let messageId = 0

onMounted(() => {
  messages.value = [
    {
      id: messageId++,
      role: 'assistant',
      text: 'Hi! I am your shop assistant. Ask about orders, returns, delivery, or products.',
    },
  ]
})

function toggle() {
  open.value = !open.value
  if (open.value) {
    void nextTick(() => scrollToBottom())
  }
}

function scrollToBottom() {
  const el = transcriptRef.value
  if (el) {
    el.scrollTop = el.scrollHeight
  }
}

function appendAssistant(data: PortalChatResponse) {
  messages.value.push({
    id: messageId++,
    role: 'assistant',
    text: data.reply,
    productMatches: data.productMatches.length ? data.productMatches : undefined,
  })
}

async function sendMessage(text: string) {
  const trimmed = text.trim()
  if (!trimmed || sending.value) {
    return
  }

  error.value = null
  messages.value.push({ id: messageId++, role: 'user', text: trimmed })
  draft.value = ''
  sending.value = true
  await nextTick()
  scrollToBottom()

  try {
    const res = await supportApi.chat(trimmed)
    appendAssistant(res.data)
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Could not reach the assistant.'
  } finally {
    sending.value = false
    await nextTick()
    scrollToBottom()
  }
}

function onSubmit() {
  void sendMessage(draft.value)
}

function handleSuggestion(label: string) {
  const normalized = label.toLowerCase()

  if (normalized.includes('contact')) {
    open.value = false
    void router.push({ name: 'contact' })
    return
  }

  if (normalized.includes('sign in') || normalized === 'login') {
    open.value = false
    void router.push({ name: 'login' })
    return
  }

  if (normalized.includes('register')) {
    open.value = false
    void router.push({ name: 'register' })
    return
  }

  if (normalized.includes('orders')) {
    open.value = false
    void router.push(
      hasToken() ? { name: 'orders' } : { name: 'login', query: { redirect: '/shop/orders' } },
    )
    return
  }

  if (normalized.includes('catalogue') || normalized.includes('view catalogue')) {
    open.value = false
    void router.push({ name: 'shop' })
    return
  }

  void sendMessage(label)
}
</script>

<template>
  <div class="pointer-events-none fixed bottom-5 right-5 z-50 flex flex-col items-end gap-3">
    <section
      v-if="open"
      class="pointer-events-auto flex h-[min(560px,calc(100vh-6rem))] w-[min(380px,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--panel)] shadow-xl"
      aria-label="Shop assistant"
    >
      <header
        class="flex items-center justify-between border-b border-[var(--line)] bg-[linear-gradient(120deg,var(--chrome)_0%,var(--brand)_100%)] px-4 py-3 text-white"
      >
        <div>
          <p class="brand text-base font-semibold">Shop assistant</p>
          <p class="text-xs text-white/80">Orders, returns, products &amp; more</p>
        </div>
        <button
          type="button"
          class="rounded-md px-2 py-1 text-sm text-white/90 hover:bg-white/10"
          aria-label="Close assistant"
          @click="open = false"
        >
          ✕
        </button>
      </header>

      <div ref="transcriptRef" class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
        <div
          v-for="msg in messages"
          :key="msg.id"
          class="flex"
          :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
        >
          <div
            class="max-w-[88%] whitespace-pre-wrap rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed"
            :class="
              msg.role === 'user'
                ? 'rounded-br-md bg-[var(--brand)] text-[var(--brand-fg)]'
                : 'rounded-bl-md border border-[var(--line)] bg-[var(--surface)] text-[var(--ink)]'
            "
          >
            {{ msg.text }}

            <ul v-if="msg.productMatches?.length" class="mt-3 space-y-2 border-t border-[var(--line)] pt-3">
              <li v-for="product in msg.productMatches" :key="product.id">
                <RouterLink
                  class="flex items-center gap-2 rounded-md bg-[var(--panel)] p-2 no-underline hover:bg-[var(--surface)]"
                  :to="{ name: 'shop-product', params: { id: product.id } }"
                  @click="open = false"
                >
                  <img
                    v-if="product.imageUrl"
                    :src="product.imageUrl"
                    :alt="product.name"
                    class="h-10 w-10 rounded object-cover"
                  />
                  <div class="min-w-0">
                    <p class="truncate font-medium text-[var(--ink)]">{{ product.name }}</p>
                    <p class="text-xs text-[var(--muted)]">
                      {{ product.sku }} · {{ formatPortalMoney(product.price?.grossPrice) }}
                    </p>
                  </div>
                </RouterLink>
              </li>
            </ul>
          </div>
        </div>

        <p v-if="sending" class="text-xs text-[var(--muted)]">Thinking…</p>
        <p v-if="error" class="text-xs text-[var(--danger)]">{{ error }}</p>
      </div>

      <div
        v-if="messages.length"
        class="border-t border-[var(--line)] px-3 py-2"
      >
        <div class="flex flex-wrap gap-2">
          <button
            v-for="suggestion in ['Track my order', 'Returns policy', 'Open contact page']"
            :key="suggestion"
            type="button"
            class="rounded-full border border-[var(--line)] px-3 py-1 text-xs text-[var(--muted)] hover:border-[var(--brand)] hover:text-[var(--brand)]"
            @click="handleSuggestion(suggestion)"
          >
            {{ suggestion }}
          </button>
        </div>
      </div>

      <form class="border-t border-[var(--line)] p-3" @submit.prevent="onSubmit">
        <div class="flex gap-2">
          <input
            v-model="draft"
            type="text"
            maxlength="1000"
            placeholder="Ask a question…"
            class="min-w-0 flex-1 rounded-full border border-[var(--line)] px-4 py-2 text-sm outline-none focus:border-[var(--brand)]"
            :disabled="sending"
            aria-label="Message to shop assistant"
          />
          <button
            type="submit"
            class="rounded-full bg-[var(--accent)] px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
            :disabled="sending || !draft.trim()"
          >
            Send
          </button>
        </div>
      </form>
    </section>

    <button
      type="button"
      class="pointer-events-auto flex h-14 w-14 items-center justify-center rounded-full bg-[var(--accent)] text-white shadow-lg transition hover:scale-105"
      :aria-expanded="open"
      aria-label="Open shop assistant"
      @click="toggle"
    >
      <span v-if="open" class="text-xl">✕</span>
      <span v-else class="text-xl" aria-hidden="true">💬</span>
    </button>
  </div>
</template>
