<template>
  <section
    id="admin-notifications"
    class="art-notification-panel art-card-sm absolute top-14.5 right-5 z-10 flex max-h-[min(32rem,calc(100dvh-5rem))] w-96 flex-col overflow-hidden !shadow-xl max-sm:right-2 max-sm:left-2 max-sm:w-auto"
    aria-labelledby="admin-notifications-title"
    :aria-busy="loading"
    @click.stop
  >
    <header class="flex items-center justify-between gap-3 border-b-d px-4 py-3">
      <div class="flex items-center gap-2">
        <h2 id="admin-notifications-title" class="text-base font-semibold text-g-900">
          {{ $t('notice.title') }}
        </h2>
        <span v-if="unreadCount" class="rounded-full bg-theme/10 px-2 py-0.5 text-xs text-theme">
          {{ unreadCount }}
        </span>
      </div>
      <ElButton
        text
        type="primary"
        :disabled="!unreadCount || markingRead"
        @click="emit('readAll')"
      >
        {{ $t('notice.readAll') }}
      </ElButton>
    </header>

    <div v-if="loadFailed" class="flex flex-col items-center gap-2 px-4 py-10 text-sm text-g-700">
      <p>{{ $t('notice.loadFailed') }}</p>
      <ElButton text type="primary" @click="emit('changePage', currentPage)">
        {{ $t('notice.retry') }}
      </ElButton>
    </div>
    <p v-else-if="loading && !records.length" class="px-4 py-10 text-center text-sm text-g-700">
      {{ $t('notice.loading') }}
    </p>
    <div v-else-if="!records.length" class="flex flex-col items-center gap-2 px-4 py-10 text-g-700">
      <ArtSvgIcon icon="ri:inbox-archive-line" class="text-3xl text-g-600" />
      <p class="text-sm">{{ $t('notice.empty') }}</p>
    </div>

    <ul v-else class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
      <li
        v-for="item in records"
        :key="item.id"
        class="flex items-start gap-3 border-b-d px-4 py-3 last:border-b-0"
      >
        <div
          class="flex size-8 shrink-0 items-center justify-center rounded-lg text-base"
          :class="item.read_at ? 'bg-g-200 text-g-700' : 'bg-theme/10 text-theme'"
          aria-hidden="true"
        >
          <ArtSvgIcon
            :icon="
              item.type === 'admin.password-changed'
                ? 'ri:shield-keyhole-line'
                : 'ri:notification-3-line'
            "
          />
        </div>
        <div class="min-w-0 flex-1">
          <h3 class="text-sm font-medium break-words text-g-900">{{ item.title }}</h3>
          <p class="mt-1 text-xs leading-5 break-words text-g-700">{{ item.message }}</p>
          <time :datetime="item.created_at" class="mt-1 block text-xs text-g-600">
            {{ dateFormatter.format(new Date(item.created_at)) }}
          </time>
        </div>
        <ElButton
          v-if="!item.read_at"
          text
          size="small"
          type="primary"
          :disabled="markingRead"
          :aria-label="$t('notice.readItem', { title: item.title })"
          @click="emit('read', item.id)"
        >
          {{ $t('notice.btnRead') }}
        </ElButton>
      </li>
    </ul>

    <footer v-if="!loadFailed && total > pageSize" class="flex justify-center border-t-d p-2">
      <ElPagination
        small
        layout="prev, pager, next"
        :current-page="currentPage"
        :page-size="pageSize"
        :pager-count="5"
        :total="total"
        :disabled="loading || markingRead"
        @update:current-page="emit('changePage', $event)"
      />
    </footer>
  </section>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import type { AdminNotification } from '@/api/admin-notification'

  defineOptions({ name: 'ArtNotification' })

  defineProps<{
    records: AdminNotification[]
    unreadCount: number
    currentPage: number
    pageSize: number
    total: number
    loading: boolean
    loadFailed: boolean
    markingRead: boolean
  }>()

  const emit = defineEmits<{
    changePage: [page: number]
    read: [id: string]
    readAll: []
  }>()

  const { locale } = useI18n()
  const dateFormatter = computed(
    () => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
  )
</script>
