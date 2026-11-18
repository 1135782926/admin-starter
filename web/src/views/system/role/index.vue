<template>
  <div class="role-page art-full-height">
    <RoleSearch v-model="searchForm" @search="applySearch" @reset="resetSearch" />
    <ElCard class="art-table-card min-h-0" :body-style="{ overflow: 'auto' }">
      <ArtTableHeader :loading="loading" layout="refresh" @refresh="loadRoles">
        <template #left>
          <ElSpace wrap>
            <ElButton v-if="hasAuth('add')" type="primary" @click="openDialog()"
              >新增角色组</ElButton
            >
            <ElButton @click="expanded = !expanded">{{
              expanded ? '全部收起' : '全部展开'
            }}</ElButton>
          </ElSpace>
        </template>
      </ArtTableHeader>
      <ElTable
        :key="String(expanded)"
        v-loading="loading"
        :data="filteredRoles"
        row-key="id"
        :default-expand-all="expanded"
        :tree-props="{ children: 'children' }"
        class="w-full"
      >
        <ElTableColumn prop="display_name" label="角色组名称" min-width="220" />
        <ElTableColumn prop="name" label="角色编码" min-width="160" />
        <ElTableColumn label="状态" min-width="150">
          <template #default="{ row }">
            <ElTag :type="row.effective_status ? 'success' : 'info'">{{
              row.effective_status ? '启用' : row.status ? '上级已禁用' : '禁用'
            }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="permissions_count" label="权限数" width="90" />
        <ElTableColumn prop="admins_count" label="管理员数" width="100" />
        <ElTableColumn prop="remark" label="备注" min-width="180" show-overflow-tooltip />
        <ElTableColumn label="操作" width="240" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="hasAuth('edit') && row.can_manage"
              link
              type="primary"
              @click="openDialog(row)"
              >编辑</ElButton
            >
            <ElButton v-else link type="info" @click="openDialog(row)">查看（只读）</ElButton>
            <ElButton
              v-if="hasAuth('add') && canAddChild(row)"
              link
              type="primary"
              @click="openDialog(undefined, row.id)"
              >新增子组</ElButton
            >
            <ElButton
              v-if="hasAuth('delete') && row.can_manage"
              link
              type="danger"
              :loading="deletingId === row.id"
              :disabled="deletingId !== undefined || hasChildren(row.id) || row.admins_count > 0"
              @click="deleteRole(row)"
              >删除</ElButton
            >
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
    <RoleEditDialog
      v-model="dialogVisible"
      :role-data="currentRole"
      :parent-id="newParentId"
      @success="loadRoles"
    />
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { fetchDeleteRole, fetchGetRoleList } from '@/api/system-manage'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import RoleSearch from './modules/role-search.vue'
  import RoleEditDialog from './modules/role-edit-dialog.vue'

  defineOptions({ name: 'Role' })
  type RoleRecord = Api.SystemManage.RoleRecord
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const searchForm = ref<Api.SystemManage.RoleSearchParams>({ keyword: '', status: undefined })
  const appliedSearch = ref<Api.SystemManage.RoleSearchParams>({})
  const roles = ref<RoleRecord[]>([])
  const loading = ref(false)
  const expanded = ref(true)
  const deletingId = ref<number>()
  const dialogVisible = ref(false)
  const currentRole = ref<RoleRecord>()
  const newParentId = ref<number>()
  let requestId = 0

  const filteredRoles = computed(() => {
    const keyword = appliedSearch.value.keyword?.toLocaleLowerCase() || ''
    const status = appliedSearch.value.status
    const filter = (nodes: RoleRecord[]): RoleRecord[] =>
      nodes.flatMap((role) => {
        const children = filter(role.children || [])
        const matches =
          (!keyword ||
            `${role.display_name} ${role.name} ${role.remark || ''}`
              .toLocaleLowerCase()
              .includes(keyword)) &&
          (typeof status !== 'boolean' || role.status === status)
        return matches || children.length ? [{ ...role, children }] : []
      })
    return filter(roles.value)
  })

  async function loadRoles() {
    const id = ++requestId
    loading.value = true
    roles.value = []
    try {
      const data = await fetchGetRoleList()
      if (id === requestId) roles.value = data
    } catch {
      // HTTP 统一显示请求错误，并拒绝旧会话响应。
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  function applySearch() {
    appliedSearch.value = { ...searchForm.value }
  }

  function resetSearch() {
    searchForm.value = { keyword: '', status: undefined }
    applySearch()
  }

  function canAddChild(row: RoleRecord) {
    return row.can_manage || (row.effective_status && userStore.info.roles?.includes(row.name))
  }

  function hasChildren(id: number, nodes = roles.value): boolean {
    return nodes.some((role) =>
      role.id === id ? !!role.children?.length : hasChildren(id, role.children || [])
    )
  }

  function openDialog(row?: RoleRecord, parentId?: number) {
    if (!row && !hasAuth('add')) return
    currentRole.value = row
    newParentId.value = parentId
    dialogVisible.value = true
  }

  async function deleteRole(row: RoleRecord) {
    if (
      !hasAuth('delete') ||
      !row.can_manage ||
      hasChildren(row.id) ||
      row.admins_count ||
      deletingId.value !== undefined
    )
      return
    try {
      await ElMessageBox.confirm(`确定删除角色组“${row.display_name}”？`, '删除角色组', {
        type: 'warning',
        confirmButtonText: '删除',
        cancelButtonText: '取消'
      })
    } catch {
      return
    }
    deletingId.value = row.id
    try {
      await fetchDeleteRole(row.id)
      ElMessage.success('角色组已删除')
      await loadRoles()
    } catch {
      // HTTP 统一显示请求错误。
    } finally {
      deletingId.value = undefined
    }
  }

  onMounted(loadRoles)
</script>
