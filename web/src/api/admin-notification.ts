import request from '@/utils/http'

export interface AdminNotification {
  id: string
  type: string
  title: string
  message: string
  read_at: string | null
  created_at: string
}

interface AdminNotificationPage {
  records: AdminNotification[]
  total: number
  current: number
  size: number
  unread_count: number
}

const notificationsUrl = '/api/v1/admin/me/notifications'

export function fetchAdminNotifications(page: number, signal: AbortSignal) {
  return request.get<AdminNotificationPage>({
    url: notificationsUrl,
    params: { page },
    signal,
    showErrorMessage: false
  })
}

export function markAdminNotificationsRead(id?: string) {
  return request.patch<null>({
    url: id ? `${notificationsUrl}/${id}/read` : `${notificationsUrl}/read-all`
  })
}
