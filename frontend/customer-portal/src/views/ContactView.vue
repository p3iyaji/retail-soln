<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError, hasToken, supportApi, type PortalSupportInfo } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const info = ref<PortalSupportInfo | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

const name = ref('')
const email = ref('')
const phone = ref('')
const subject = ref('')
const message = ref('')

const submitting = ref(false)
const submitError = ref<string | null>(null)
const submitted = ref(false)
const successMessage = ref('')

onMounted(async () => {
  if (hasToken() && !auth.account) {
    try {
      await auth.fetchMe()
    } catch {
      /* guest-style form still works */
    }
  }

  name.value = auth.account?.customer?.name ?? ''
  email.value = auth.account?.email ?? ''
  phone.value = auth.account?.customer?.phone ?? ''

  try {
    const res = await supportApi.info()
    info.value = res.data
  } catch (err) {
    loadError.value = err instanceof ApiError ? err.message : 'Could not load contact details.'
  } finally {
    loading.value = false
  }
})

async function onSubmit() {
  submitting.value = true
  submitError.value = null
  submitted.value = false

  try {
    const res = await supportApi.contact({
      name: name.value.trim(),
      email: email.value.trim(),
      phone: phone.value.trim() || null,
      subject: subject.value.trim(),
      message: message.value.trim(),
    })
    submitted.value = true
    successMessage.value = res.data.message
    subject.value = ''
    message.value = ''
  } catch (err) {
    if (err instanceof ApiError && err.body.errors) {
      const first = Object.values(err.body.errors)[0]?.[0]
      submitError.value = first ?? err.message
    } else {
      submitError.value = err instanceof ApiError ? err.message : 'Could not send your message.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="px-5 py-10">
    <div class="mx-auto max-w-[900px]">
      <section
        class="mb-8 rounded-xl bg-[linear-gradient(120deg,var(--chrome)_0%,var(--brand)_100%)] px-6 py-8 text-white"
      >
        <h1 class="brand text-3xl font-semibold">Contact us</h1>
        <p class="mt-2 max-w-xl text-sm text-white/85">
          Questions about products, orders, or delivery? Send us a message and our team will respond
          as soon as possible.
        </p>
      </section>

      <div class="grid gap-8 lg:grid-cols-[1fr_1.1fr]">
        <aside class="space-y-4">
          <div
            v-if="loading"
            class="rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6 text-sm text-[var(--muted)]"
          >
            Loading store details…
          </div>
          <div
            v-else-if="loadError"
            class="rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6 text-sm text-[var(--danger)]"
          >
            {{ loadError }}
          </div>
          <template v-else-if="info">
            <div class="rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6">
              <h2 class="text-lg font-semibold">{{ info.organizationName }}</h2>
              <dl class="mt-4 space-y-3 text-sm">
                <div v-if="info.supportEmail">
                  <dt class="text-[var(--muted)]">Email</dt>
                  <dd>
                    <a
                      class="text-[var(--brand)] no-underline hover:underline"
                      :href="`mailto:${info.supportEmail}`"
                    >
                      {{ info.supportEmail }}
                    </a>
                  </dd>
                </div>
                <div v-if="info.supportPhone">
                  <dt class="text-[var(--muted)]">Phone</dt>
                  <dd>
                    <a
                      class="text-[var(--brand)] no-underline hover:underline"
                      :href="`tel:${info.supportPhone}`"
                    >
                      {{ info.supportPhone }}
                    </a>
                  </dd>
                </div>
                <div v-if="info.businessHours">
                  <dt class="text-[var(--muted)]">Business hours</dt>
                  <dd>{{ info.businessHours }}</dd>
                </div>
              </dl>
            </div>

            <div
              v-if="info.stores.length"
              class="rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6"
            >
              <h3 class="text-sm font-semibold uppercase tracking-wide text-[var(--muted)]">
                Our stores
              </h3>
              <ul class="mt-4 space-y-4">
                <li v-for="store in info.stores" :key="store.id" class="text-sm">
                  <p class="font-medium">{{ store.name }}</p>
                  <p v-if="store.address" class="mt-1 text-[var(--muted)]">{{ store.address }}</p>
                </li>
              </ul>
            </div>
          </template>

          <p class="text-sm text-[var(--muted)]">
            Prefer quick answers?
            <RouterLink class="text-[var(--brand)] no-underline hover:underline" :to="{ name: 'shop' }">
              Use the shop assistant
            </RouterLink>
            in the bottom-right corner.
          </p>
        </aside>

        <form
          class="rounded-xl border border-[var(--line)] bg-[var(--panel)] p-6"
          @submit.prevent="onSubmit"
        >
          <h2 class="text-lg font-semibold">Send a message</h2>
          <p class="mt-1 text-sm text-[var(--muted)]">
            All fields marked with * are required.
          </p>

          <div
            v-if="submitted"
            class="mt-4 rounded-md border border-[var(--brand)]/20 bg-[var(--brand)]/5 px-4 py-3 text-sm text-[var(--brand)]"
            role="status"
          >
            {{ successMessage }}
          </div>
          <p v-if="submitError" class="mt-4 text-sm text-[var(--danger)]">{{ submitError }}</p>

          <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs text-[var(--muted)]" for="contact-name">Name *</label>
              <input
                id="contact-name"
                v-model="name"
                type="text"
                required
                maxlength="120"
                class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
              />
            </div>
            <div>
              <label class="mb-1 block text-xs text-[var(--muted)]" for="contact-email">Email *</label>
              <input
                id="contact-email"
                v-model="email"
                type="email"
                required
                maxlength="255"
                class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
              />
            </div>
            <div>
              <label class="mb-1 block text-xs text-[var(--muted)]" for="contact-phone">Phone</label>
              <input
                id="contact-phone"
                v-model="phone"
                type="tel"
                maxlength="64"
                class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
              />
            </div>
            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs text-[var(--muted)]" for="contact-subject">Subject *</label>
              <input
                id="contact-subject"
                v-model="subject"
                type="text"
                required
                maxlength="200"
                class="w-full rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
              />
            </div>
            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs text-[var(--muted)]" for="contact-message">Message *</label>
              <textarea
                id="contact-message"
                v-model="message"
                required
                rows="6"
                maxlength="5000"
                class="w-full resize-y rounded-md border border-[var(--line)] px-3 py-2 outline-none focus:border-[var(--brand)]"
              />
            </div>
          </div>

          <button
            type="submit"
            class="mt-6 rounded-md bg-[var(--brand)] px-5 py-2.5 text-sm font-medium text-[var(--brand-fg)] disabled:opacity-60"
            :disabled="submitting"
          >
            {{ submitting ? 'Sending…' : 'Send message' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
