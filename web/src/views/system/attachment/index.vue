<template>
  <div class="attachment-page art-full-height">
    <ElCard v-if="!canView" shadow="never">
      <ElEmpty description="暂无附件查看权限" />
    </ElCard>
    <template v-else>
      <ArtSearchBar
        v-model="searchForm"
        :items="searchItems"
        label-position="top"
        @search="search"
        @reset="resetSearch"
      />
      <ElCard class="art-table-card min-h-64" :body-style="{ overflow: 'auto' }">
        <ArtTableHeader :loading="loading" layout="refresh" @refresh="loadAttachments">
          <template #left>
            <ElSpace wrap>
              <ElButton v-if="hasAuth('add')" type="primary" @click="uploadVisible = true">
                上传附件
              </ElButton>
              <ElButton
                v-if="hasAuth('delete')"
                type="danger"
                plain
                :disabled="!selected.length || deleting"
                :loading="deleting"
                @click="confirmDelete(selected)"
              >
                批量删除{{ selected.length ? `（${selected.length}）` : '' }}
              </ElButton>
            </ElSpace>
          </template>
        </ArtTableHeader>
        <ElAlert
          v-if="failed"
          title="附件加载失败，请使用刷新重试。"
          type="error"
          :closable="false"
          class="mb-3"
        />
        <ArtTable
          :loading="loading"
          :columns="columns"
          :data="records"
          row-key="id"
          :empty-text="failed ? '附件加载失败' : '暂无符合条件的附件'"
          :pagination="pagination"
          :pagination-options="paginationOptions"
          @selection-change="selected = $event"
          @pagination:current-change="changePage"
          @pagination:size-change="changeSize"
        >
          <template #preview="{ row }">
            <button
              v-if="row.is_image"
              type="button"
              class="flex size-12 cursor-pointer items-center justify-center overflow-hidden rounded border border-g-300 bg-g-100 dark:border-g-700"
              :title="`预览 ${row.title}`"
              @click="openPreview(row)"
            >
              <img
                v-if="previewUrls[row.id]"
                :src="previewUrls[row.id]"
                :alt="row.title"
                loading="lazy"
                class="size-full object-cover"
              />
              <ElIcon v-else class="text-xl text-g-500"><Picture /></ElIcon>
            </button>
            <span v-else class="text-g-500">—</span>
          </template>
          <template #title="{ row }">
            <div class="flex min-w-0 items-center gap-2">
              <ElIcon class="shrink-0 text-lg text-primary">
                <Picture v-if="row.is_image" />
                <Box v-else-if="row.extension === 'zip'" />
                <Document v-else />
              </ElIcon>
              <div class="min-w-0">
                <p class="m-0 truncate" :title="row.title">{{ row.title }}</p>
                <p class="m-0 truncate text-xs text-g-500" :title="row.original_name">
                  {{ row.original_name }}
                </p>
              </div>
            </div>
          </template>
          <template #category="{ row }">
            <ElTag v-if="row.category" size="small">{{ row.category }}</ElTag>
            <span v-else class="text-g-500">未分类</span>
          </template>
          <template #visibility="{ row }">
            <ElTag :type="row.visibility === 'public' ? 'success' : 'info'" size="small">
              {{ row.visibility === 'public' ? '公开' : '私有' }}
            </ElTag>
          </template>
          <template #uploader="{ row }">
            <template v-if="row.uploader">
              <span>{{ row.uploader.nickname || row.uploader.username }}</span>
              <span class="ml-1 text-xs text-g-500">ID {{ row.uploader.id }}</span>
            </template>
            <span v-else class="text-g-500">—</span>
          </template>
          <template #operation="{ row }">
            <ElButton v-if="row.is_image" link type="primary" @click="openPreview(row)">
              预览
            </ElButton>
            <ElButton
              link
              type="primary"
              :disabled="downloadingId === row.id"
              @click="download(row)"
            >
              {{ downloadingId === row.id ? '获取中' : '下载' }}
            </ElButton>
            <ElButton v-if="hasAuth('edit')" link type="primary" @click="openEdit(row)">
              编辑
            </ElButton>
            <ElButton
              v-if="hasAuth('delete')"
              link
              type="danger"
              :disabled="deleting"
              @click="confirmDelete([row])"
            >
              删除
            </ElButton>
          </template>
        </ArtTable>
      </ElCard>
    </template>

    <AttachmentUploadDialog v-model:visible="uploadVisible" @uploaded="loadAttachments" />
    <AttachmentEditDialog
      v-model:visible="editVisible"
      :attachment="currentAttachment"
      @submit="loadAttachments"
    />
    <AttachmentPreviewDialog v-model:visible="previewVisible" :attachment="currentAttachment" />

    <ElDialog
      v-model="deleteResultVisible"
      title="删除结果"
      width="min(560px, calc(100% - 32px))"
      align-center
    >
      <ElAlert
        :title="deleteSummary"
        :type="deleteResults.some((item) => !item.success) ? 'warning' : 'success'"
        :closable="false"
        class="mb-3"
      />
      <div
        v-for="item in deleteResults"
        :key="item.id"
        class="flex items-start justify-between gap-3 border-b border-g-300 py-2 last:border-0 dark:border-g-700"
      >
        <div class="min-w-0">
          <p class="m-0 break-all text-sm">{{ item.title }}</p>
          <p v-if="!item.success" class="mb-0 mt-1 text-xs text-danger">{{ item.message }}</p>
        </div>
        <ElTag :type="item.success ? 'success' : 'danger'" size="small" class="shrink-0">
          {{ item.success ? '已删除' : '失败' }}
        </ElTag>
      </div>
      <template #footer>
        <ElButton type="primary" @click="deleteResultVisible = false">知道了</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { ElMessageBox } from 'element-plus'
  import { Box, Document, Picture } from '@element-plus/icons-vue'
  import {
    fetchDeleteAttachment,
    fetchGetAttachmentList,
    fetchGetAttachmentTemporaryUrl
  } from '@/api/attachment'
  import type { SearchFormItem } from '@/components/core/forms/art-search-bar/index.vue'
  import type { ColumnOption } from '@/types'
  import { useAuth } from '@/hooks/core/useAuth'
  import AttachmentUploadDialog from './modules/attachment-upload-dialog.vue'
  import AttachmentEditDialog from './modules/attachment-edit-dialog.vue'
  import AttachmentPreviewDialog from './modules/attachment-preview-dialog.vue'

  defineOptions({ name: 'Attachment' })

  type AttachmentRecord = Api.Attachment.Record
  type SearchForm = Api.Attachment.SearchParams & { dateRange?: [string, string] }
  interface DeleteResult {
    id: number
    title: string
    success: boolean
    message?: string
  }

  const { hasAuth } = useAuth()
  const canView = computed(() => hasAuth('view'))
  const searchForm = ref<SearchForm>({})
  const appliedSearch = ref<Api.Attachment.SearchParams>({})
  const records = ref<AttachmentRecord[]>([])
  const previewUrls = ref<Record<number, string>>({})
  const selected = ref<AttachmentRecord[]>([])
  const pagination = reactive({ current: 1, size: 20, total: 0 })
  const paginationOptions = {
    pageSizes: [10, 20, 50, 100],
    layout: 'total, sizes, prev, pager, next',
    align: 'right' as const
  }
  const loading = ref(false)
  const failed = ref(false)
  const uploadVisible = ref(false)
  const editVisible = ref(false)
  const previewVisible = ref(false)
  const currentAttachment = ref<AttachmentRecord>()
  const downloadingId = ref<number>()
  const deleting = ref(false)
  const deleteResultVisible = ref(false)
  const deleteResults = ref<DeleteResult[]>([])
  let requestId = 0

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
        placeholder: '附件名称、原始文件名或分类',
        onKeydown: searchOnEnter
      }
    },
    {
      label: '分类',
      key: 'category',
      type: 'input',
      props: { maxlength: 100, clearable: true, placeholder: '附件分类', onKeydown: searchOnEnter }
    },
    {
      label: '类型',
      key: 'type',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部类型',
        options: [
          { label: '图片', value: 'image' },
          { label: '文档', value: 'document' },
          { label: '压缩包', value: 'archive' },
          { label: '文本', value: 'text' }
        ]
      }
    },
    {
      label: '公开性',
      key: 'visibility',
      type: 'select',
      props: {
        clearable: true,
        placeholder: '全部',
        options: [
          { label: '私有', value: 'private' },
          { label: '公开', value: 'public' }
        ]
      }
    },
    {
      label: '上传人 ID',
      key: 'admin_user_id',
      type: 'number',
      props: {
        min: 1,
        precision: 0,
        controls: false,
        placeholder: '管理员 ID',
        class: '!w-full',
        onKeydown: searchOnEnter
      }
    },
    {
      label: '上传时间（本地时区）',
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
  const columns: ColumnOption<AttachmentRecord>[] = [
    { type: 'selection', width: 48 },
    { prop: 'preview', label: '图片', width: 76, align: 'center', useSlot: true },
    { prop: 'title', label: '名称', minWidth: 240, useSlot: true },
    { prop: 'category', label: '分类', minWidth: 120, useSlot: true },
    {
      prop: 'extension',
      label: '类型',
      width: 90,
      formatter: (row) => `${row.extension.toUpperCase()}${row.is_image ? ' 图片' : ''}`
    },
    { prop: 'size', label: '大小', width: 110, formatter: (row) => formatSize(row.size) },
    { prop: 'visibility', label: '公开性', width: 90, useSlot: true },
    { prop: 'uploader', label: '上传人', minWidth: 150, useSlot: true },
    { prop: 'created_at', label: '上传时间', minWidth: 170 },
    { prop: 'operation', label: '操作', width: 240, fixed: 'right', useSlot: true }
  ]
  const deleteSummary = computed(() => {
    const succeeded = deleteResults.value.filter((item) => item.success).length
    const failedCount = deleteResults.value.length - succeeded
    return failedCount
      ? `成功删除 ${succeeded} 项，${failedCount} 项失败；失败项仍保留在附件库。`
      : `已成功删除 ${succeeded} 项。`
  })

  async function loadAttachments() {
    if (!canView.value) return
    const id = ++requestId
    loading.value = true
    failed.value = false
    try {
      const data = await fetchGetAttachmentList({
        ...appliedSearch.value,
        page: pagination.current,
        per_page: pagination.size
      })
      if (id !== requestId) return
      records.value = data.records
      const publicPreviewUrls: Record<number, string> = {}
      for (const row of data.records) {
        if (row.is_image && row.url) publicPreviewUrls[row.id] = row.url
      }
      previewUrls.value = publicPreviewUrls
      void loadPrivatePreviewUrls(data.records, id)
      pagination.total = data.total
      pagination.current = data.current
      pagination.size = data.size
    } catch {
      if (id === requestId) {
        failed.value = true
        records.value = []
      }
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  async function loadPrivatePreviewUrls(rows: AttachmentRecord[], loadId: number) {
    const loadedUrls: Record<number, string> = {}
    await Promise.all(
      rows
        .filter((row) => row.is_image && !row.url)
        .map(async (row) => {
          try {
            const data = await fetchGetAttachmentTemporaryUrl(row.id, 'inline')
            loadedUrls[row.id] = data.url
          } catch {
            // 保留占位图标，点击预览时会重新获取图片地址。
          }
        })
    )
    if (loadId !== requestId) return
    previewUrls.value = { ...previewUrls.value, ...loadedUrls }
  }

  function search() {
    const form = searchForm.value
    appliedSearch.value = {
      keyword: form.keyword?.trim() || undefined,
      category: form.category?.trim() || undefined,
      type: form.type || undefined,
      visibility: form.visibility || undefined,
      admin_user_id: typeof form.admin_user_id === 'number' ? form.admin_user_id : undefined,
      date_from: form.dateRange?.[0],
      date_to: form.dateRange?.[1]
    }
    pagination.current = 1
    void loadAttachments()
  }

  function resetSearch() {
    searchForm.value = {}
    search()
  }

  function searchOnEnter(event: KeyboardEvent) {
    if (event.key !== 'Enter' || event.isComposing) return
    event.preventDefault()
    search()
  }

  function changePage(value: number) {
    pagination.current = value
    void loadAttachments()
  }

  function changeSize(value: number) {
    pagination.size = value
    pagination.current = 1
    void loadAttachments()
  }

  function openPreview(row: AttachmentRecord) {
    currentAttachment.value = row
    previewVisible.value = true
  }

  function openEdit(row: AttachmentRecord) {
    currentAttachment.value = row
    editVisible.value = true
  }

  async function download(row: AttachmentRecord) {
    if (downloadingId.value !== undefined) return
    downloadingId.value = row.id
    try {
      const data = await fetchGetAttachmentTemporaryUrl(row.id, 'attachment')
      const link = document.createElement('a')
      link.href = data.url
      link.download = row.original_name
      document.body.appendChild(link)
      link.click()
      link.remove()
    } catch {
      // 临时下载地址获取失败由 HTTP 统一提示。
    } finally {
      downloadingId.value = undefined
    }
  }

  async function confirmDelete(rows: AttachmentRecord[]) {
    if (!rows.length || deleting.value) return
    try {
      await ElMessageBox.confirm(
        `确定永久删除选中的 ${rows.length} 个附件？文件和附件记录将直接删除且不可恢复，系统不会检测业务引用。`,
        rows.length > 1 ? '批量永久删除' : '永久删除附件',
        {
          type: 'warning',
          confirmButtonText: '永久删除',
          cancelButtonText: '取消',
          confirmButtonClass: 'el-button--danger'
        }
      )
    } catch {
      return
    }
    deleting.value = true
    const results: DeleteResult[] = []
    for (const row of rows) {
      try {
        await fetchDeleteAttachment(row.id)
        results.push({ id: row.id, title: row.title, success: true })
      } catch (error) {
        results.push({
          id: row.id,
          title: row.title,
          success: false,
          message: error instanceof Error && error.message ? error.message : '删除失败'
        })
      }
    }
    deleteResults.value = results
    deleteResultVisible.value = true
    deleting.value = false
    selected.value = []
    const succeeded = results.filter((item) => item.success).length
    if (succeeded && records.value.length === succeeded && pagination.current > 1) {
      pagination.current--
    }
    await loadAttachments()
  }

  function formatSize(bytes: number) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
  }

  onMounted(loadAttachments)
  onBeforeUnmount(() => requestId++)
</script>
