import request from '@/utils/http'

/**
 * 登录
 * @param params 登录参数
 * @returns 登录响应
 */
export function fetchLogin(params: Api.Auth.LoginParams) {
  return request.post<Api.Auth.LoginResponse>({
    url: '/api/v1/admin/auth/login',
    data: params,
    skipAuth: true
  })
}

/**
 * 获取用户信息
 * @returns 用户信息
 */
export function fetchGetUserInfo() {
  return request.get<Api.Auth.UserInfo>({
    url: '/api/v1/admin/me'
  })
}

export function fetchLogout() {
  return request.post<null>({
    url: '/api/v1/admin/auth/logout',
    showErrorMessage: false
  })
}

export function fetchGetProfile() {
  return request.get<Api.Auth.Profile>({ url: '/api/v1/admin/me/profile' })
}

export function fetchGetPersonalLogs(page: number) {
  return request.get<Api.Auth.PersonalLogList>({ url: '/api/v1/admin/me/logs', params: { page } })
}

export function fetchUpdateProfile(data: Api.Auth.ProfileInput, avatar?: File) {
  const url = '/api/v1/admin/me/profile'
  if (!avatar) return request.patch<Api.Auth.Profile>({ url, data })

  const formData = new FormData()
  formData.append('_method', 'PATCH')
  for (const [key, value] of Object.entries(data)) formData.append(key, value ?? '')
  formData.append('avatar', avatar)

  return request.post<Api.Auth.Profile>({ url, data: formData })
}

export function fetchChangePassword(data: Api.Auth.ChangePasswordInput) {
  return request.patch<null>({ url: '/api/v1/admin/me/password', data })
}
