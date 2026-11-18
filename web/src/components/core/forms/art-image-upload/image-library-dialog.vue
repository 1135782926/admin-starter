<template>
  <ElDialog
    v-model="visible"
    title="选择文件"
    width="min(1200px, calc(100% - 32px))"
    append-to-body
    align-center
    destroy-on-close
    :close-on-click-modal="false"
  >
    <ArtSearchBar
      v-model="searchForm"
      :items="searchItems"
      :show-expand="false"
      :show-reset="false"
      @search="search"
      @submit.prevent="search"
    />
    <div class="mb-2 flex items-center gap-3">
      <ElButton :icon="Refresh" :disabled="loading || selectingId !== undefined" @click="loadImages"
        >刷新</ElButton
      >
      <span class="text-xs text-g-500">可选择 1 张公开图片</span>
    </div>
    <ArtTable
      :columns="columns"
      :data="records"
      :loading="loading"
      :pagination="pagination"
      :pagination-options="paginationOptions"
      :show-table-header="false"
      :empty-text="failed ? '图片加载失败，请刷新重试' : '没有符合条件的公开图片'"
      row-key="id"
      empty-height="220px"
      max-height="52vh"
      stripe
      @pagination:current-change="changePage"
      @pagination:size-change="changeSize"
    >
      <template #preview="{ row }">
        <img
          v-if="row.url"
          :src="row.url"
          :alt="row.title"
          loading="lazy"
          class="mx-auto size-9 rounded object-cover"
        />
      </template>
      <template #operation="{ row }">
        <ElButton
          :disabled="!row.url || row.size > maxBytes || selectingId !== undefined"
          :loading="selectingId === row.id"
          type="primary"
          size="small"
          @click="select(row)"
        >
          {{ row.size > maxBytes ? '超过大小限制' : '选择' }}
        </ElButton>
      </template>
    </ArtTable>
  </ElDialog>
</template>

<script setup lang="ts">
  import { Refresh } from '@element-plus/icons-vue'
  import { ElMessage } from 'element-plus'
  import { fetchGetAttachmentList, fetchGetAttachmentTemporaryUrl } from '@/api/attachment'
  import type { SearchFormItem } from '@/components/core/forms/art-search-bar/index.vue'
  import type { ColumnOption } from '@/types'

  const props = defineProps<{ maxBytes: number }>()
  const visible = defineModel<boolean>('visible', { required: true })
  const emit = defineEmits<{ select: [file: File] }>()
  const searchForm = ref({ keyword: '' })
  const keyword = ref('')
  const records = shallowRef<Api.Attachment.Record[]>([])
  const pagination = reactive({ current: 1, size: 8, total: 0 })
  const paginationOptions = { pageSizes: [8, 16, 32] }
  const loading = ref(false)
  const failed = ref(false)
  const selectingId = ref<number>()
  let listRequest = 0
  let selection: AbortController | undefined

  const searchItems: SearchFormItem[] = [
    {
      key: 'keyword',
      label: '',
      type: 'input',
      span: 12,
      props: { clearable: true, maxlength: 255, placeholder: '搜索附件名称、文件名或分类' }
    }
  ]
  const columns: ColumnOption<Api.Attachment.Record>[] = [
    { prop: 'id', label: 'ID', width: 75 },
    { prop: 'category', label: '分类', minWidth: 100 },
    { prop: 'preview', label: '预览', width: 80, align: 'center', useSlot: true },
    { prop: 'mime_type', label: '类型', width: 110 },
    {
      prop: 'size',
      label: '大小',
      width: 100,
      formatter: ({ size }) => (size < 1024 ? `${size} B` : `${(size / 1024).toFixed(1)} KB`)
    },
    { prop: 'created_at', label: '上传时间', width: 170 },
    { prop: 'original_name', label: '原始名称', minWidth: 160, showOverflowTooltip: true },
    { prop: 'operation', label: '操作', width: 120, fixed: 'right', align: 'center', useSlot: true }
  ]

  watch(visible, (open) => {
    if (open) {
      searchForm.value = { keyword: '' }
      keyword.value = ''
      pagination.current = 1
      void loadImages()
    } else {
      cancelRequests()
    }
  })
  onBeforeUnmount(cancelRequests)

  function cancelRequests() {
    listRequest++
    selection?.abort()
    selection = undefined
    selectingId.value = undefined
  }

  async function loadImages() {
    const requestId = ++listRequest
    loading.value = true
    failed.value = false
    records.value = []
    try {
      const data = await fetchGetAttachmentList({
        type: 'image',
        visibility: 'public',
        page: pagination.current,
        per_page: pagination.size,
        keyword: keyword.value || undefined
      })
      if (requestId !== listRequest) return
      pagination.total = data.total
      records.value = data.records
    } catch {
      if (requestId === listRequest) failed.value = true
    } finally {
      if (requestId === listRequest) loading.value = false
    }
  }

  function search() {
    keyword.value = searchForm.value.keyword?.trim() || ''
    changePage(1)
  }

  function changePage(page: number) {
    pagination.current = page
    void loadImages()
  }

  function changeSize(size: number) {
    pagination.size = size
    changePage(1)
  }

  async function select(row: Api.Attachment.Record) {
    if (!row.url || row.size > props.maxBytes || selection) return
    const controller = new AbortController()
    selection = controller
    selectingId.value = row.id
    try {
      const { url } = await fetchGetAttachmentTemporaryUrl(row.id, 'inline')
      if (controller.signal.aborted) return
      const response = await fetch(url, { signal: controller.signal, credentials: 'omit' })
      if (!response.ok) throw new Error(`HTTP ${response.status}`)
      const blob = await response.blob()
      if (controller.signal.aborted) return
      emit('select', new File([blob], row.original_name, { type: blob.type }))
      visible.value = false
    } catch {
      if (!controller.signal.aborted) ElMessage.error('无法读取附件图片，请刷新后重试')
    } finally {
      if (selection === controller) {
        selection = undefined
        selectingId.value = undefined
      }
    }
  }
</script>
