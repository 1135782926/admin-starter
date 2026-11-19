import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { Ref } from 'vue'
import {
  fetchAdminNotifications,
  markAdminNotificationsRead,
  type AdminNotification
} from '@/api/admin-notification'
import { useUserStore } from '@/store/modules/user'

const {
  VITE_API_URL,
  VITE_REVERB_APP_KEY,
  VITE_REVERB_HOST,
  VITE_REVERB_PORT,
  VITE_REVERB_SCHEME
} = import.meta.env

export function useAdminNotifications(enabled: Ref<boolean>) {
  const userStore = useUserStore()
  const notifications = ref<AdminNotification[]>([])
  const unreadCount = ref(0)
  const currentPage = ref(1)
  const pageSize = ref(15)
  const total = ref(0)
  const loading = ref(false)
  const loadFailed = ref(false)
  const markingRead = ref(false)
  let pendingLoad: AbortController | undefined

  async function loadPage(page = currentPage.value): Promise<void> {
    if (!enabled.value || !userStore.isLogin || !userStore.accessToken || !userStore.info.userId) {
      return
    }
    currentPage.value = page

    const session = userStore.getSessionVersion()
    pendingLoad?.abort()
    const load = new AbortController()
    pendingLoad = load
    loading.value = true
    loadFailed.value = false

    try {
      const data = await fetchAdminNotifications(page, load.signal)
      if (load.signal.aborted || session !== userStore.getSessionVersion()) return

      notifications.value = data.records
      unreadCount.value = data.unread_count
      currentPage.value = data.current
      pageSize.value = data.size
      total.value = data.total
    } catch {
      if (!load.signal.aborted && session === userStore.getSessionVersion()) loadFailed.value = true
    } finally {
      if (pendingLoad === load) {
        pendingLoad = undefined
        loading.value = false
      }
    }
  }

  async function markRead(id?: string): Promise<void> {
    if (markingRead.value) return

    const session = userStore.getSessionVersion()
    markingRead.value = true
    try {
      await markAdminNotificationsRead(id)
      if (session === userStore.getSessionVersion()) await loadPage()
    } catch {
      // 请求封装会显示写入失败，保留当前未读状态以便重试。
    } finally {
      if (session === userStore.getSessionVersion()) markingRead.value = false
    }
  }

  watch(
    [enabled, () => (userStore.isLogin ? userStore.accessToken : ''), () => userStore.info.userId],
    ([isEnabled, token, adminId], _previous, onCleanup) => {
      notifications.value = []
      unreadCount.value = 0
      currentPage.value = 1
      total.value = 0
      loading.value = false
      loadFailed.value = false
      markingRead.value = false
      if (!isEnabled || !token || !adminId) return

      const session = userStore.getSessionVersion()
      const refresh = () => {
        if (session === userStore.getSessionVersion()) void loadPage()
      }
      onCleanup(() => {
        pendingLoad?.abort()
        pendingLoad = undefined
      })

      refresh()
      if (!VITE_REVERB_APP_KEY) return

      const secure = (VITE_REVERB_SCHEME || window.location.protocol.slice(0, -1)) === 'https'
      const port = Number(VITE_REVERB_PORT || (secure ? 443 : 8080))
      const apiBase = (VITE_API_URL || '').replace(/\/+$/, '')
      const echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: VITE_REVERB_APP_KEY,
        wsHost: VITE_REVERB_HOST || window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        withoutInterceptors: true,
        channelAuthorization: {
          transport: 'ajax',
          endpoint: `${apiBase}/api/v1/admin/broadcasting/auth`,
          headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' }
        }
      })
      const channel = `admin.${adminId}`
      echo.private(channel).listen('.admin.notifications.updated', refresh).subscribed(refresh)

      onCleanup(() => {
        echo.leave(channel)
        echo.disconnect()
      })
    },
    { immediate: true }
  )

  return {
    notifications,
    unreadCount,
    currentPage,
    pageSize,
    total,
    loading,
    loadFailed,
    markingRead,
    loadPage,
    markRead
  }
}
