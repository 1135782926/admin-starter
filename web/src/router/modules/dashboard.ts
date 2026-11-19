import { AppRouteRecord } from '@/types/router'

export const dashboardConsoleRoute = {
  path: 'console',
  // 冒号保留给内置路由，避免与后端菜单标识重名。
  name: 'dashboard:console',
  component: '/dashboard/console',
  meta: {
    title: 'menus.dashboard.console',
    icon: 'ri:home-smile-2-line',
    keepAlive: false,
    fixedTab: true
  }
} satisfies AppRouteRecord

export const dashboardRoutes: AppRouteRecord = {
  name: 'Dashboard',
  path: '/dashboard',
  component: '/index/index',
  meta: {
    title: 'menus.dashboard.title',
    icon: 'ri:pie-chart-line',
    roles: ['super-admin', 'admin']
  },
  children: [
    dashboardConsoleRoute,
    {
      path: 'analysis',
      name: 'Analysis',
      component: '/dashboard/analysis',
      meta: {
        title: 'menus.dashboard.analysis',
        icon: 'ri:align-item-bottom-line',
        keepAlive: false
      }
    },
    {
      path: 'ecommerce',
      name: 'Ecommerce',
      component: '/dashboard/ecommerce',
      meta: {
        title: 'menus.dashboard.ecommerce',
        icon: 'ri:bar-chart-box-line',
        keepAlive: false
      }
    }
  ]
}
