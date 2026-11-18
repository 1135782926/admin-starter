import request from '@/utils/http'

export function fetchGetAdminLogList(params: Api.SystemManage.AdminLogSearchParams) {
  return request.get<Api.SystemManage.AdminLogList>({
    url: '/api/v1/admin/logs',
    params: {
      ...params,
      success: typeof params.success === 'boolean' ? Number(params.success) : undefined
    }
  })
}

export function fetchGetAdminLog(id: number) {
  return request.get<Api.SystemManage.AdminLogRecord>({ url: `/api/v1/admin/logs/${id}` })
}
