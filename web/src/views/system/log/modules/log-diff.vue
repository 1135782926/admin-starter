<template>
  <ElTable v-if="rows.length" :data="rows" border class="w-full">
    <ElTableColumn prop="label" label="字段" min-width="130" />
    <ElTableColumn label="变更前" min-width="180">
      <template #default="{ row }">
        <span class="whitespace-pre-wrap break-all">{{ row.before }}</span>
      </template>
    </ElTableColumn>
    <ElTableColumn label="变更后" min-width="180">
      <template #default="{ row }">
        <ElTag v-if="row.changed" type="info">已修改</ElTag>
        <span v-else class="whitespace-pre-wrap break-all">{{ row.after }}</span>
      </template>
    </ElTableColumn>
  </ElTable>
  <p v-else class="text-sm text-g-500">无字段变更。</p>
</template>

<script setup lang="ts">
  const props = defineProps<{ changes?: Api.SystemManage.AdminLogChanges | null }>()

  const fieldLabels: Record<string, string> = {
    username: '用户名',
    nickname: '昵称',
    email: '邮箱（脱敏）',
    mobile: '手机号（脱敏）',
    password: '密码',
    avatar: '头像',
    member_group_id: '会员分组 ID',
    gender: '性别',
    birthday: '生日',
    signature: '个性签名',
    status: '状态',
    role_ids: '角色组 ID',
    permission_ids: '权限 ID',
    granted_role_ids: '受影响角色组 ID',
    direct_admin_ids: '受影响直接授权管理员 ID',
    name: '名称 / 权限标识',
    display_name: '显示名称',
    parent_id: '上级 ID',
    remark: '备注',
    type: '菜单类型',
    title: '菜单标题',
    permission_id: '权限 ID',
    open_type: '打开方式',
    path: '路由地址',
    component: '页面组件',
    url: '链接地址',
    icon: '图标',
    sort: '排序',
    keep_alive: '页面缓存'
  }

  function formatValue(field: string, value: Api.SystemManage.AdminLogValue): string {
    if (value === null) return '—'
    if (typeof value === 'boolean') {
      return field === 'status' ? (value ? '启用' : '禁用') : value ? '是' : '否'
    }
    if (Array.isArray(value))
      return value.length ? value.map((item) => formatValue(field, item)).join('、') : '空列表'
    if (typeof value === 'object') {
      return (
        Object.entries(value)
          .map(([key, item]) => `${key}：${formatValue(key, item)}`)
          .join('\n') || '空值'
      )
    }
    if (field === 'gender') {
      const labels: Record<string, string> = { unknown: '未知', male: '男', female: '女' }
      return labels[String(value)] || String(value)
    }
    if (field === 'type') {
      return (
        ({ directory: '目录', menu: '菜单', button: '操作按钮' } as Record<string, string>)[
          String(value)
        ] || String(value)
      )
    }
    if (field === 'open_type') {
      return (
        ({ page: '站内页面', iframe: '内嵌页面', external: '外部链接' } as Record<string, string>)[
          String(value)
        ] || String(value)
      )
    }
    return value === '' ? '空文本' : String(value)
  }

  const rows = computed(() =>
    Object.entries(props.changes || {})
      .filter(([field]) => Object.hasOwn(fieldLabels, field))
      .map(([field, change]) => ({
        field,
        label: fieldLabels[field],
        changed: 'changed' in change,
        before: 'before' in change ? formatValue(field, change.before) : '—',
        after: 'after' in change ? formatValue(field, change.after) : '已修改'
      }))
  )
</script>
