<template>
  <div class="log-page art-full-height">
    <ElCard v-if="!canView" shadow="never">
      <ElEmpty description="暂无管理员日志查看权限" />
    </ElCard>
    <template v-else>
      <ArtSearchBar
        v-model="searchForm"
        :items="searchItems"
        label-position="top"
        :default-expanded="true"
        @search="search"
        @reset="search"
      />
      <ElCard class="art-table-card min-h-64" :body-style="{ overflow: 'auto' }">
        <ArtTableHeader :loading="loading" layout="refresh" @refresh="loadLogs">
          <template #left>
            <div>
              <span class="text-base font-medium">管理员日志</span>
              <p class="text-xs text-g-500">日志只读，仅展示当前权限范围内的记录。</p>
            </div>
          </template>
        </ArtTableHeader>
        <ElAlert
          v-if="failed"
          title="日志加载失败，请使用刷新重试。"
          type="error"
          :closable="false"
          class="mb-3"
        />
        <ArtTable
          :loading="loading"
          :columns="columns"
          :data="records"
          row-key="id"
          :empty-text="failed ? '日志加载失败' : '暂无符合条件的日志'"
          :pagination="pagination"
          :pagination-options="paginationOptions"
          @pagination:current-change="changePage"
          @pagination:size-change="changeSize"
        >
          <template #actor="{ row }">
            <div class="flex flex-col gap-1">
              <span>{{ formatLogActor(row) }}</span>
              <span v-if="row.actor_id !== null" class="text-xs text-g-500">
                管理员 ID {{ row.actor_id }}
              </span>
              <span v-else-if="row.actor_username || row.actor_nickname" class="text-xs text-g-500">
                历史身份快照（账号已删除或无法关联）
              </span>
              <span v-if="row.attempted_username !== null" class="break-all text-xs text-g-500">
                尝试账号（未验证）：{{ row.attempted_username }}
              </span>
            </div>
          </template>
          <template #success="{ row }">
            <ElTag :type="row.success ? 'success' : 'danger'">{{ formatLogResult(row) }}</ElTag>
            <span class="ml-2 text-xs text-g-500">{{ row.status_code }}</span>
          </template>
          <template #operation="{ row }">
            <ElButton v-if="canView" type="primary" link @click="openDetail(row)">详情</ElButton>
          </template>
        </ArtTable>
      </ElCard>
      <LogDetail v-model:visible="detailVisible" :log-id="selectedId" />
    </template>
  </div>
</template>

<script setup lang="ts">
  import { fetchGetAdminLogList } from '@/api/admin-log'
  import type { SearchFormItem } from '@/components/core/forms/art-search-bar/index.vue'
  import type { ColumnOption } from '@/types'
  import { useAuth } from '@/hooks/core/useAuth'
  import LogDetail from './modules/log-detail.vue'
  import {
    actionLabels,
    moduleLabels,
    formatLogActor,
    formatLogResult,
    formatLogTarget,
    formatLogTime
  } from './modules/log-display'

  defineOptions({ name: 'AdminLog' })

  type LogRecord = Api.SystemManage.AdminLogRecord
  type LogSearchForm = Api.SystemManage.AdminLogSearchParams & {
    dateRange?: [string, string]
  }

  const { hasAuth } = useAuth()

  const canView = computed(() => hasAuth('view'))
  const searchForm = ref<LogSearchForm>({})
  const appliedSearch = ref<Api.SystemManage.AdminLogSearchParams>({})
  const defaultTime: [Date, Date] = [
    new Date(2000, 0, 1, 0, 0, 0),
    new Date(2000, 0, 1, 23, 59, 59)
  ]
  const searchItems: SearchFormItem[] = [
    {
      label: '关键词',
      key: 'keyword',
      type: 'input',
      props: {
        maxlength: 255,
        clearable: true,
        placeholder: '操作人、尝试登录账号或对象名称',
        onKeydown: searchOnEnter
      }
    },
    {
      label: '模块',
      key: 'module',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部模块',
        options: Object.entries(moduleLabels).map(([value, label]) => ({ value, label }))
      }
    },
    {
      label: '操作',
      key: 'action',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部操作',
        options: Object.entries(actionLabels).map(([value, label]) => ({ value, label }))
      }
    },
    {
      label: '结果',
      key: 'success',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部结果',
        options: [
          { label: '成功', value: true },
          { label: '失败', value: false }
        ]
      }
    },
    {
      label: '操作人 ID',
      key: 'actor_id',
      type: 'number',
      props: {
        min: 1,
        precision: 0,
        controls: false,
        placeholder: '已验证管理员 ID',
        class: '!w-full',
        onKeydown: searchOnEnter
      }
    },
    {
      label: 'HTTP 状态码',
      key: 'status_code',
      type: 'number',
      props: {
        min: 100,
        max: 599,
        precision: 0,
        controls: false,
        placeholder: '例如 403',
        class: '!w-full',
        onKeydown: searchOnEnter
      }
    },
    {
      label: '来源 IP',
      key: 'ip_address',
      type: 'input',
      props: {
        maxlength: 45,
        clearable: true,
        placeholder: '完整 IPv4 / IPv6 地址',
        onKeydown: searchOnEnter
      }
    },
    {
      label: '操作时间（本地时区）',
      key: 'dateRange',
      type: 'datetimerange',
      span: 24,
      props: {
        type: 'datetimerange',
        startPlaceholder: '开始时间',
        endPlaceholder: '结束时间',
        rangeSeparator: '至',
        format: 'YYYY-MM-DD HH:mm:ss',
        valueFormat: 'YYYY-MM-DDTHH:mm:ss.SSSZ',
        defaultTime,
        class: '!w-full'
      }
    }
  ]

  const columns: ColumnOption<LogRecord>[] = [
    {
      prop: 'created_at',
      label: '操作时间',
      minWidth: 180,
      formatter: (row) => formatLogTime(row.created_at)
    },
    { prop: 'actor', label: '操作人', minWidth: 240, useSlot: true },
    { prop: 'module', label: '模块', width: 110, formatter: (row) => moduleLabels[row.module] },
    { prop: 'action', label: '操作', width: 130, formatter: (row) => actionLabels[row.action] },
    {
      prop: 'target_label',
      label: '操作对象',
      minWidth: 220,
      showOverflowTooltip: true,
      formatter: formatLogTarget
    },
    { prop: 'success', label: '结果', minWidth: 180, useSlot: true },
    { prop: 'ip_address', label: '来源 IP', minWidth: 150, showOverflowTooltip: true },
    { prop: 'duration_ms', label: '耗时', width: 100, formatter: (row) => `${row.duration_ms} ms` },
    { prop: 'operation', label: '操作', width: 90, fixed: 'right', useSlot: true }
  ]

  const records = ref<LogRecord[]>([])
  const pagination = reactive({ current: 1, size: 20, total: 0 })
  const paginationOptions = {
    pageSizes: [10, 20, 50, 100],
    layout: 'total, sizes, prev, pager, next',
    align: 'right' as const
  }
  const loading = ref(false)
  const failed = ref(false)
  const detailVisible = ref(false)
  const selectedId = ref<number>()
  let requestId = 0

  async function loadLogs() {
    if (!canView.value) return
    const id = ++requestId
    loading.value = true
    failed.value = false
    records.value = []
    try {
      const data = await fetchGetAdminLogList({
        ...appliedSearch.value,
        page: pagination.current,
        per_page: pagination.size
      })
      if (id !== requestId) return
      records.value = data.records
      pagination.total = data.total
      pagination.current = data.current
      pagination.size = data.size
    } catch {
      if (id === requestId) failed.value = true
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  function search() {
    const form = searchForm.value
    appliedSearch.value = {
      keyword: form.keyword?.trim() || undefined,
      module: form.module || undefined,
      action: form.action || undefined,
      success: typeof form.success === 'boolean' ? form.success : undefined,
      actor_id: typeof form.actor_id === 'number' ? form.actor_id : undefined,
      status_code: typeof form.status_code === 'number' ? form.status_code : undefined,
      ip_address: form.ip_address?.trim() || undefined,
      date_from: form.dateRange?.[0],
      date_to: form.dateRange?.[1]
    }
    pagination.current = 1
    void loadLogs()
  }

  function searchOnEnter(event: KeyboardEvent) {
    if (event.key !== 'Enter' || event.isComposing) return
    event.preventDefault()
    search()
  }

  function changePage(value: number) {
    if (value === pagination.current) return
    pagination.current = value
    void loadLogs()
  }

  function changeSize(value: number) {
    pagination.size = value
    pagination.current = 1
    void loadLogs()
  }

  function openDetail(row: LogRecord) {
    if (!hasAuth('view')) return
    selectedId.value = row.id
    detailVisible.value = true
  }

  onMounted(loadLogs)
  onBeforeUnmount(() => requestId++)
</script>
