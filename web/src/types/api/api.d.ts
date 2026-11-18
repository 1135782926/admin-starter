/**
 * API 接口类型定义模块
 *
 * 提供所有后端接口的类型定义
 *
 * ## 主要功能
 *
 * - 通用类型（分页参数、响应结构等）
 * - 认证类型（登录、用户信息等）
 * - 系统管理类型（用户、角色等）
 * - 全局命名空间声明
 *
 * ## 使用场景
 *
 * - API 请求参数类型约束
 * - API 响应数据类型定义
 * - 接口文档类型同步
 *
 * ## 注意事项
 *
 * - 在 .vue 文件使用需要在 eslint.config.mjs 中配置 globals: { Api: 'readonly' }
 * - 使用全局命名空间，无需导入即可使用
 *
 * ## 使用方式
 *
 * ```typescript
 * const params: Api.Auth.LoginParams = { userName: 'admin', password: '123456' }
 * const response: Api.Auth.UserInfo = await fetchUserInfo()
 * ```
 *
 * @module types/api/api
 * @author Art Design Pro Team
 */

declare namespace Api {
  /** 通用类型 */
  namespace Common {
    /** 分页参数 */
    interface PaginationParams {
      /** 当前页码 */
      current: number
      /** 每页条数 */
      size: number
      /** 总条数 */
      total: number
    }

    /** 通用搜索参数 */
    type CommonSearchParams = Pick<PaginationParams, 'current' | 'size'>

    /** 分页响应基础结构 */
    interface PaginatedResponse<T = any> {
      records: T[]
      current: number
      size: number
      total: number
    }

    /** 启用状态 */
    type EnableStatus = '1' | '2'
  }

  /** 认证类型 */
  namespace Auth {
    /** 登录参数 */
    interface LoginParams {
      userName: string
      password: string
    }

    /** 登录响应 */
    interface LoginResponse {
      token: string
    }

    /** 用户信息 */
    interface UserInfo {
      buttons: string[]
      roles: string[]
      userId: number
      userName: string
      email: string
      avatar?: string
    }
  }

  /** 系统管理类型 */
  namespace SystemManage {
    interface AdminRecord {
      id: number
      username: string
      nickname: string | null
      email: string | null
      mobile: string | null
      status: boolean
      role_ids: number[]
      roles: { id: number; name: string; display_name: string }[]
      created_at: string | null
      updated_at: string | null
      can_manage: boolean
    }

    interface AdminSearchParams {
      page?: number
      per_page?: number
      keyword?: string
      status?: boolean
    }

    type AdminList = Api.Common.PaginatedResponse<AdminRecord>

    type AdminLogModule = 'auth' | 'admin' | 'role' | 'menu'
    type AdminLogAction = 'login' | 'logout' | 'create' | 'update' | 'delete'
    type AdminLogValue =
      | string
      | number
      | boolean
      | null
      | AdminLogValue[]
      | { [key: string]: AdminLogValue }
    type AdminLogChanges = Record<
      string,
      { before: AdminLogValue; after: AdminLogValue } | { changed: true }
    >

    interface AdminLogRecord {
      id: number
      actor_id: number | null
      actor_username: string | null
      actor_nickname: string | null
      attempted_username: string | null
      module: AdminLogModule
      action: AdminLogAction
      permission_name: string | null
      route_name: string
      method: string
      path: string
      target_type: 'admin' | 'role' | 'menu' | null
      target_id: number | null
      target_label: string | null
      status_code: number
      success: boolean
      ip_address: string | null
      user_agent: string | null
      duration_ms: number
      created_at: string
      changes?: AdminLogChanges | null
      related_changes?:
        | {
            type: 'role' | 'menu'
            id: number
            label: string
            changes: AdminLogChanges
          }[]
        | null
    }

    interface AdminLogSearchParams {
      page?: number
      per_page?: number
      keyword?: string
      actor_id?: number
      module?: AdminLogModule
      action?: AdminLogAction
      success?: boolean
      status_code?: number
      ip_address?: string
      date_from?: string
      date_to?: string
    }

    type AdminLogList = Api.Common.PaginatedResponse<AdminLogRecord>

    interface AdminInput {
      username: string
      nickname: string
      password?: string
      email: string | null
      mobile: string | null
      status: boolean
      role_ids: number[]
    }

    interface RoleOption {
      id: number
      name: string
      display_name: string
      parent_id: number | null
      status: boolean
      effective_status: boolean
      can_assign: boolean
    }

    interface RoleRecord {
      id: number
      name: string
      display_name: string
      parent_id: number | null
      status: boolean
      effective_status: boolean
      remark: string | null
      permission_ids: number[]
      permissions_count: number
      admins_count: number
      can_manage: boolean
      created_at: string | null
      updated_at: string | null
      children?: RoleRecord[]
    }

    interface RoleSearchParams {
      keyword?: string
      status?: boolean
    }

    interface RoleInput {
      name: string
      display_name: string
      parent_id: number | null
      status: boolean
      remark: string | null
      permission_ids: number[]
    }

    interface MenuPermissionNode {
      id: number
      key: string
      parent_id: number | null
      type: 'directory' | 'menu' | 'button'
      title: string
      permission_id: number | null
      permission_name: string | null
      status: boolean
      effective_status: boolean
      depends_on: number[]
      assignable: boolean
      children: MenuPermissionNode[]
    }
  }
}
