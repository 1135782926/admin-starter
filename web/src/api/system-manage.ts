import request from '@/utils/http'
import { AppRouteRecord } from '@/types/router'

export function fetchGetAdminList(params: Api.SystemManage.AdminSearchParams) {
  return request.get<Api.SystemManage.AdminList>({
    url: '/api/v1/admin/admins',
    params: {
      ...params,
      status: typeof params.status === 'boolean' ? Number(params.status) : undefined
    }
  })
}

export function fetchGetAdmin(id: number) {
  return request.get<Api.SystemManage.AdminRecord>({ url: `/api/v1/admin/admins/${id}` })
}

export function fetchCreateAdmin(data: Api.SystemManage.AdminInput) {
  return request.post<Api.SystemManage.AdminRecord>({ url: '/api/v1/admin/admins', data })
}

export function fetchUpdateAdmin(id: number, data: Partial<Api.SystemManage.AdminInput>) {
  return request.patch<Api.SystemManage.AdminRecord>({ url: `/api/v1/admin/admins/${id}`, data })
}

export function fetchDeleteAdmin(id: number) {
  return request.del<null>({ url: `/api/v1/admin/admins/${id}`, data: { confirmed: true } })
}

export function fetchGetRoleList() {
  return request.get<Api.SystemManage.RoleRecord[]>({ url: '/api/v1/admin/roles' })
}

export function fetchGetRole(id: number) {
  return request.get<Api.SystemManage.RoleRecord>({ url: `/api/v1/admin/roles/${id}` })
}

export function fetchGetRoleOptions() {
  return request.get<Api.SystemManage.RoleOption[]>({ url: '/api/v1/admin/roles/options' })
}

export function fetchGetRolePermissionTree() {
  return request.get<Api.SystemManage.MenuPermissionNode[]>({
    url: '/api/v1/admin/roles/permission-tree'
  })
}

export function fetchCreateRole(data: Api.SystemManage.RoleInput) {
  return request.post<Api.SystemManage.RoleRecord>({ url: '/api/v1/admin/roles', data })
}

export function fetchUpdateRole(
  id: number,
  data: Partial<Omit<Api.SystemManage.RoleInput, 'name'>>
) {
  return request.patch<Api.SystemManage.RoleRecord>({ url: `/api/v1/admin/roles/${id}`, data })
}

export function fetchDeleteRole(id: number) {
  return request.del<null>({ url: `/api/v1/admin/roles/${id}`, data: { confirmed: true } })
}

// 获取菜单列表
export function fetchGetMenuList() {
  return request.get<AppRouteRecord[]>({
    url: '/api/v1/admin/me/menus'
  })
}

// 菜单管理树（含按钮和停用节点）
export function fetchGetAdminMenus() {
  return request.get<Api.SystemManage.MenuRecord[]>({
    url: '/api/v1/admin/menus',
    showErrorMessage: false
  })
}

export function fetchGetAdminMenu(id: number) {
  return request.get<Api.SystemManage.MenuRecord>({
    url: `/api/v1/admin/menus/${id}`,
    showErrorMessage: false
  })
}

export function fetchCreateAdminMenu(data: Api.SystemManage.MenuInput) {
  return request.post<Api.SystemManage.MenuRecord>({
    url: '/api/v1/admin/menus',
    data,
    showErrorMessage: false
  })
}

export function fetchUpdateAdminMenu(id: number, data: Api.SystemManage.MenuUpdateInput) {
  return request.patch<Api.SystemManage.MenuRecord>({
    url: `/api/v1/admin/menus/${id}`,
    data,
    showErrorMessage: false
  })
}

export function fetchGetMenuDeletionImpact(id: number) {
  return request.get<Api.SystemManage.MenuDeletionImpact>({
    url: `/api/v1/admin/menus/${id}/deletion-impact`,
    showErrorMessage: false
  })
}

export function fetchDeleteAdminMenu(id: number) {
  return request.del<null>({
    url: `/api/v1/admin/menus/${id}`,
    data: { confirmed: true },
    showErrorMessage: false
  })
}
