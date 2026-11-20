export const moduleLabels: Record<Api.SystemManage.AdminLogModule, string> = {
  auth: '登录认证',
  admin: '管理员',
  role: '角色组',
  menu: '菜单',
  attachment: '附件',
  member: '会员',
  member_group: '会员分组',
  profile: '个人中心'
}

export const actionLabels: Record<Api.SystemManage.AdminLogAction, string> = {
  login: '登录',
  logout: '退出登录',
  create: '新增',
  update: '修改 / 授权',
  delete: '删除',
  change_password: '修改密码'
}

export function formatLogTime(value: string) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('zh-CN', { hour12: false })
}

export function formatLogActor(log: Api.SystemManage.AdminLogRecord) {
  if (log.actor_username && log.actor_nickname) {
    return `${log.actor_nickname}（${log.actor_username}）`
  }
  return log.actor_username || log.actor_nickname || '未关联管理员'
}

export function formatLogTarget(log: Api.SystemManage.AdminLogRecord) {
  const type = log.target_type ? moduleLabels[log.target_type] : ''
  const id = log.target_id !== null ? ` #${log.target_id}` : ''
  return `${type}${id}${log.target_label ? ` · ${log.target_label}` : ''}` || '—'
}

export function formatLogResult(log: Api.SystemManage.AdminLogRecord) {
  if (log.success) return '成功'
  const labels: Record<number, string> = {
    401: '身份验证失败',
    403: '无操作权限',
    404: '资源不存在或不可访问',
    409: '操作冲突',
    422: '参数校验失败',
    429: '请求过于频繁'
  }
  return labels[log.status_code] || (log.status_code >= 500 ? '服务处理失败' : '操作失败')
}
