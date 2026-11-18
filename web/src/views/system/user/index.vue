<template>
  <div class="user-page art-full-height">
    <UserSearch v-model="searchForm" @search="search" @reset="resetSearch" />
    <ElCard class="art-table-card min-h-0" :body-style="{ overflow: 'auto' }">
      <ArtTableHeader :loading="loading" layout="refresh" @refresh="loadAdmins">
        <template #left>
          <ElButton v-if="hasAuth('add')" type="primary" @click="openDialog()">新增管理员</ElButton>
        </template>
      </ArtTableHeader>
      <ElTable v-loading="loading" :data="records" row-key="id" class="w-full">
        <ElTableColumn prop="username" label="用户名" min-width="140" />
        <ElTableColumn prop="nickname" label="昵称" min-width="120" />
        <ElTableColumn prop="email" label="邮箱" min-width="180" show-overflow-tooltip />
        <ElTableColumn prop="mobile" label="手机号" min-width="140" />
        <ElTableColumn label="角色组" min-width="180">
          <template #default="{ row }">
            <div class="flex flex-wrap gap-1">
              <ElTag v-for="role in row.roles" :key="role.id" size="small">{{
                role.display_name
              }}</ElTag>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="90">
          <template #default="{ row }">
            <ElTag :type="row.status ? 'success' : 'info'">{{
              row.status ? '启用' : '禁用'
            }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="created_at" label="创建时间" min-width="180" />
        <ElTableColumn label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <ElButton
              v-if="hasAuth('edit') && row.can_manage"
              link
              type="primary"
              @click="openDialog(row)"
              >编辑</ElButton
            >
            <ElButton
              v-if="hasAuth('delete') && row.can_manage && row.id !== userStore.info.userId"
              link
              type="danger"
              :loading="deletingId === row.id"
              :disabled="deletingId !== undefined"
              @click="deleteAdmin(row)"
              >删除</ElButton
            >
            <span v-if="!row.can_manage" class="text-sm text-g-500">只读</span>
          </template>
        </ElTableColumn>
      </ElTable>
      <div class="mt-4 flex justify-end overflow-x-auto">
        <ElPagination
          :current-page="page"
          :page-size="size"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          background
          @current-change="changePage"
          @size-change="changeSize"
        />
      </div>
    </ElCard>
    <UserDialog v-model:visible="dialogVisible" :user-data="currentAdmin" @submit="loadAdmins" />
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { fetchDeleteAdmin, fetchGetAdminList } from '@/api/system-manage'
  import { useAuth } from '@/hooks/core/useAuth'
  import { useUserStore } from '@/store/modules/user'
  import UserSearch from './modules/user-search.vue'
  import UserDialog from './modules/user-dialog.vue'

  defineOptions({ name: 'User' })
  const { hasAuth } = useAuth()
  const userStore = useUserStore()
  const searchForm = ref<Api.SystemManage.AdminSearchParams>({ keyword: '', status: undefined })
  const appliedSearch = ref<Api.SystemManage.AdminSearchParams>({})
  const records = ref<Api.SystemManage.AdminRecord[]>([])
  const page = ref(1)
  const size = ref(20)
  const total = ref(0)
  const loading = ref(false)
  const deletingId = ref<number>()
  const dialogVisible = ref(false)
  const currentAdmin = ref<Api.SystemManage.AdminRecord>()
  let requestId = 0

  async function loadAdmins() {
    const id = ++requestId
    loading.value = true
    records.value = []
    try {
      const data = await fetchGetAdminList({
        ...appliedSearch.value,
        page: page.value,
        per_page: size.value
      })
      if (id !== requestId) return
      records.value = data.records
      total.value = data.total
      page.value = data.current
      size.value = data.size
    } catch {
      // HTTP 统一显示请求错误，并拒绝旧会话响应。
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  function search() {
    appliedSearch.value = {
      keyword: searchForm.value.keyword?.trim() || undefined,
      status: typeof searchForm.value.status === 'boolean' ? searchForm.value.status : undefined
    }
    page.value = 1
    void loadAdmins()
  }

  function resetSearch() {
    searchForm.value = { keyword: '', status: undefined }
    search()
  }

  function changePage(value: number) {
    page.value = value
    void loadAdmins()
  }

  function changeSize(value: number) {
    size.value = value
    page.value = 1
    void loadAdmins()
  }

  function openDialog(row?: Api.SystemManage.AdminRecord) {
    if (row ? !hasAuth('edit') || !row.can_manage : !hasAuth('add')) return
    currentAdmin.value = row
    dialogVisible.value = true
  }

  async function deleteAdmin(row: Api.SystemManage.AdminRecord) {
    if (
      !hasAuth('delete') ||
      !row.can_manage ||
      row.id === userStore.info.userId ||
      deletingId.value !== undefined
    )
      return
    try {
      await ElMessageBox.confirm(
        `确定删除管理员“${row.username}”？该账号的登录会话将被清除。`,
        '删除管理员',
        {
          type: 'warning',
          confirmButtonText: '删除',
          cancelButtonText: '取消'
        }
      )
    } catch {
      return
    }
    deletingId.value = row.id
    try {
      await fetchDeleteAdmin(row.id)
      ElMessage.success('管理员已删除')
      if (records.value.length === 1 && page.value > 1) page.value--
      await loadAdmins()
    } catch {
      // HTTP 统一显示请求错误。
    } finally {
      deletingId.value = undefined
    }
  }

  onMounted(loadAdmins)
</script>
