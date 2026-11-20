<template>
  <div class="art-full-height">
    <ElCard v-if="!canView" shadow="never">
      <ElEmpty description="暂无会员查看权限" />
    </ElCard>
    <template v-else>
      <ArtSearchBar
        v-model="searchForm"
        :items="searchItems"
        label-position="top"
        @search="search"
        @reset="search"
      />
      <ElAlert v-if="groupsFailed" type="error" :closable="false" class="mb-3">
        <span>会员分组加载失败。</span>
        <ElButton link type="primary" @click="loadGroups">重试</ElButton>
      </ElAlert>
      <ElCard class="art-table-card min-h-64" :body-style="{ overflow: 'auto' }">
        <ArtTableHeader :loading="loading" layout="refresh" @refresh="refresh">
          <template #left>
            <ElSpace wrap>
              <ElButton v-if="hasAuth('add')" type="primary" @click="openMember()"
                >新增会员</ElButton
              >
              <ElButton v-if="hasAuth('manage_groups')" @click="openGroups">分组管理</ElButton>
            </ElSpace>
          </template>
        </ArtTableHeader>
        <ElAlert
          v-if="failed"
          title="会员列表加载失败，请使用刷新重试。"
          type="error"
          :closable="false"
          class="mb-3"
        />
        <ArtTable
          :columns="columns"
          :data="records"
          :loading="loading"
          :pagination="pagination"
          :pagination-options="paginationOptions"
          :empty-text="failed ? '会员列表加载失败' : '暂无符合条件的会员'"
          row-key="id"
          @pagination:current-change="changePage"
          @pagination:size-change="changeSize"
        >
          <template #avatar="{ row }">
            <ElAvatar
              :src="row.avatar ?? undefined"
              :icon="UserFilled"
              :size="32"
              :alt="`${row.nickname}的头像`"
            />
          </template>
          <template #status="{ row }">
            <ElTag :type="row.status ? 'success' : 'info'" size="small">
              {{ row.status ? '启用' : '禁用' }}
            </ElTag>
          </template>
          <template #operation="{ row }">
            <ArtButtonTable
              type="edit"
              role="button"
              tabindex="0"
              title="编辑会员"
              :aria-label="`编辑会员 ${row.username}`"
              @click="openMember(row.id)"
              @keydown.enter.prevent="openMember(row.id)"
              @keydown.space.prevent="openMember(row.id)"
            />
          </template>
        </ArtTable>
      </ElCard>
      <MemberDialog
        v-model:visible="memberVisible"
        :member-id="memberId"
        :groups="groups"
        :groups-loading="groupsLoading"
        :groups-failed="groupsFailed"
        @retry-groups="loadGroups"
        @submit="loadMembers"
      />
      <MemberGroupsDialog
        v-if="hasAuth('manage_groups')"
        v-model:visible="groupsVisible"
        :groups="groups"
        :loading="groupsLoading"
        :failed="groupsFailed"
        @retry="loadGroups"
        @submit="applyGroup"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
  import { UserFilled } from '@element-plus/icons-vue'
  import {
    fetchGetMemberGroups,
    fetchGetMemberList,
    type MemberGroup,
    type MemberRecord,
    type MemberSearchParams
  } from '@/api/member'
  import type { SearchFormItem } from '@/components/core/forms/art-search-bar/index.vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import type { ColumnOption } from '@/types'
  import MemberDialog from './modules/member-dialog.vue'
  import MemberGroupsDialog from './modules/member-groups-dialog.vue'

  defineOptions({ name: 'Member' })

  type SearchForm = Pick<MemberSearchParams, 'keyword' | 'member_group_id' | 'status'>

  const { hasAuth } = useAuth()
  const canView = computed(() => hasAuth('view'))
  const searchForm = ref<SearchForm>({})
  const appliedSearch = ref<SearchForm>({})
  const records = shallowRef<MemberRecord[]>([])
  const groups = shallowRef<MemberGroup[]>([])
  const pagination = reactive({ current: 1, size: 20, total: 0 })
  const paginationOptions = {
    pageSizes: [10, 20, 50, 100],
    layout: 'total, sizes, prev, pager, next',
    align: 'right' as const
  }
  const loading = ref(false)
  const failed = ref(false)
  const groupsLoading = ref(false)
  const groupsFailed = ref(false)
  const memberVisible = ref(false)
  const groupsVisible = ref(false)
  const memberId = ref<number>()
  let listRequest = 0
  let groupRequest = 0

  const searchItems = computed<SearchFormItem[]>(() => [
    {
      label: '关键词',
      key: 'keyword',
      type: 'input',
      props: {
        clearable: true,
        maxlength: 255,
        placeholder: '用户名、昵称、电子邮箱或手机号',
        onKeydown: searchOnEnter
      }
    },
    {
      label: '分组',
      key: 'member_group_id',
      type: 'select',
      props: {
        clearable: true,
        filterable: true,
        loading: groupsLoading.value,
        disabled: groupsFailed.value,
        placeholder: groupsLoading.value ? '正在加载会员分组…' : '全部分组',
        options: groups.value.map((group) => ({ label: group.name, value: group.id }))
      }
    },
    {
      label: '状态',
      key: 'status',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部状态',
        options: [
          { label: '启用', value: true },
          { label: '禁用', value: false }
        ]
      }
    }
  ])

  const columns = computed<ColumnOption<MemberRecord>[]>(() => {
    const tableColumns: ColumnOption<MemberRecord>[] = [
      { prop: 'avatar', label: '头像', width: 76, align: 'center', useSlot: true },
      { prop: 'username', label: '用户名', minWidth: 140, showOverflowTooltip: true },
      { prop: 'nickname', label: '昵称', minWidth: 120, showOverflowTooltip: true },
      { prop: 'group.name', label: '分组', minWidth: 120, showOverflowTooltip: true },
      { prop: 'email', label: '电子邮箱', minWidth: 180, showOverflowTooltip: true },
      { prop: 'mobile', label: '手机号', minWidth: 140 },
      { prop: 'status', label: '状态', width: 90, useSlot: true },
      { prop: 'created_at', label: '创建时间', minWidth: 180 }
    ]
    if (hasAuth('edit')) {
      tableColumns.push({
        prop: 'operation',
        label: '操作',
        width: 82,
        fixed: 'right',
        useSlot: true
      })
    }
    return tableColumns
  })

  async function loadMembers() {
    if (!canView.value) return
    const request = ++listRequest
    loading.value = true
    failed.value = false
    try {
      const data = await fetchGetMemberList({
        ...appliedSearch.value,
        page: pagination.current,
        per_page: pagination.size
      })
      if (request !== listRequest) return
      records.value = data.records
      pagination.current = data.current
      pagination.size = data.size
      pagination.total = data.total
    } catch {
      if (request !== listRequest) return
      records.value = []
      pagination.total = 0
      failed.value = true
    } finally {
      if (request === listRequest) loading.value = false
    }
  }

  async function loadGroups() {
    if (!canView.value || groupsLoading.value) return
    const request = ++groupRequest
    groupsLoading.value = true
    groupsFailed.value = false
    try {
      const data = await fetchGetMemberGroups()
      if (request === groupRequest) groups.value = data
    } catch {
      if (request === groupRequest) groupsFailed.value = true
    } finally {
      if (request === groupRequest) groupsLoading.value = false
    }
  }

  function search() {
    const { keyword, member_group_id, status } = searchForm.value
    appliedSearch.value = {
      keyword: keyword?.trim() || undefined,
      member_group_id: typeof member_group_id === 'number' ? member_group_id : undefined,
      status: typeof status === 'boolean' ? status : undefined
    }
    pagination.current = 1
    void loadMembers()
  }

  function searchOnEnter(event: KeyboardEvent) {
    if (event.key !== 'Enter' || event.isComposing) return
    event.preventDefault()
    search()
  }

  function changePage(value: number) {
    if (value === pagination.current) return
    pagination.current = value
    void loadMembers()
  }

  function changeSize(value: number) {
    if (value === pagination.size) return
    pagination.size = value
    pagination.current = 1
    void loadMembers()
  }

  function refresh() {
    void loadMembers()
    void loadGroups()
  }

  function openMember(id?: number) {
    memberId.value = id
    memberVisible.value = true
    void loadGroups()
  }

  function openGroups() {
    groupsVisible.value = true
    void loadGroups()
  }

  function applyGroup(group: MemberGroup) {
    if (groups.value.some((item) => item.id === group.id)) {
      groups.value = groups.value.map((item) => (item.id === group.id ? group : item))
      void loadMembers()
    } else {
      groups.value = [...groups.value, group]
    }
  }

  onMounted(refresh)
  onBeforeUnmount(() => {
    listRequest++
    groupRequest++
  })
</script>
