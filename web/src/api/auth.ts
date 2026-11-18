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
