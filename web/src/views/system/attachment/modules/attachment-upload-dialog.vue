<template>
  <ElDialog
    v-model="visible"
    title="上传附件"
    width="min(720px, calc(100% - 32px))"
    align-center
    :close-on-click-modal="false"
    :close-on-press-escape="!uploading"
    :show-close="!uploading"
    @closed="reset"
  >
    <ElForm label-width="90px" :disabled="uploading">
      <ElFormItem label="分类">
        <ElInput v-model="category" maxlength="100" clearable placeholder="选填，例如：公告资料" />
      </ElFormItem>
      <ElFormItem label="公开性">
        <ElRadioGroup v-model="visibility">
          <ElRadio value="private">私有</ElRadio>
          <ElRadio value="public">公开（仅图片）</ElRadio>
        </ElRadioGroup>
      </ElFormItem>
    </ElForm>
    <ElUpload
      drag
      multiple
      :auto-upload="false"
      :show-file-list="false"
      :disabled="uploading || items.length >= 10"
      :accept="accept"
      :on-change="addFile"
    >
      <div class="px-4 py-5">
        <ElIcon class="mb-2 text-4xl text-primary"><UploadFilled /></ElIcon>
        <p class="m-0">拖拽文件到此处，或点击选择</p>
        <p class="mb-0 mt-2 text-xs text-g-500">
          最多 10 个，每个不超过 50MB；支持图片、Office 文档、PDF、TXT、ZIP
        </p>
      </div>
    </ElUpload>
    <ElAlert
      v-if="visibility === 'public'"
      title="公开上传仅允许图片；非图片会被拒绝。每次上传都会创建独立文件和附件记录。"
      type="info"
      :closable="false"
      class="mt-3"
    />
    <div v-if="items.length" class="mt-4 max-h-64 overflow-y-auto">
      <div
        v-for="item in items"
        :key="item.key"
        class="flex items-start justify-between gap-3 border-b border-g-300 py-2 last:border-0 dark:border-g-700"
      >
        <div class="min-w-0">
          <p class="m-0 break-all text-sm">{{ item.file.name }}</p>
          <p class="mb-0 mt-1 text-xs" :class="resultClass(item.status)">
            {{ item.message || statusLabels[item.status] }}
          </p>
        </div>
        <ElTag :type="tagType(item.status)" size="small" class="shrink-0">
          {{ statusLabels[item.status] }}
        </ElTag>
      </div>
    </div>
    <template #footer>
      <ElButton :disabled="uploading" @click="items = []">清空列表</ElButton>
      <ElButton :disabled="uploading" @click="visible = false">关闭</ElButton>
      <ElButton
        type="primary"
        :loading="uploading"
        :disabled="!items.some((item) => item.status === 'pending')"
        @click="upload"
      >
        开始上传
      </ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage, type TagProps, type UploadFile } from 'element-plus'
  import { UploadFilled } from '@element-plus/icons-vue'
  import { fetchUploadAttachment } from '@/api/attachment'

  type ItemStatus = 'pending' | 'uploading' | 'success' | 'failed'
  interface UploadItem {
    key: number
    file: File
    status: ItemStatus
    message?: string
  }

  const visible = defineModel<boolean>('visible', { required: true })
  const emit = defineEmits<{ uploaded: [] }>()
  const category = ref('')
  const visibility = ref<Api.Attachment.Visibility>('private')
  const items = ref<UploadItem[]>([])
  const uploading = ref(false)
  let sequence = 0
  const maxSize = 50 * 1024 * 1024
  const imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif']
  const allowedExtensions = [...imageExtensions, 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip']
  const accept = allowedExtensions.map((extension) => `.${extension}`).join(',')
  const statusLabels: Record<ItemStatus, string> = {
    pending: '等待上传',
    uploading: '上传中',
    success: '上传成功',
    failed: '上传失败'
  }

  function extensionOf(name: string) {
    return name.includes('.') ? name.split('.').pop()!.toLowerCase() : ''
  }

  function addFile(uploadFile: UploadFile) {
    if (!uploadFile.raw || uploading.value) return
    if (items.value.length >= 10) {
      ElMessage.warning('每次最多选择 10 个文件')
      return
    }
    const file = uploadFile.raw
    const extension = extensionOf(file.name)
    const item: UploadItem = { key: ++sequence, file, status: 'pending' }
    if (!allowedExtensions.includes(extension)) {
      item.status = 'failed'
      item.message = '文件扩展名不在允许范围内'
    } else if (file.size > maxSize) {
      item.status = 'failed'
      item.message = '文件超过 50MB 上限'
    } else if (visibility.value === 'public' && !imageExtensions.includes(extension)) {
      item.status = 'failed'
      item.message = '非图片只能私有上传，请改为私有后重新选择'
    }
    items.value.push(item)
  }

  function errorMessage(error: unknown) {
    return error instanceof Error && error.message ? error.message : '上传失败'
  }

  async function upload() {
    if (uploading.value) return
    uploading.value = true
    let succeeded = 0
    for (const item of items.value) {
      if (item.status !== 'pending') continue
      if (visibility.value === 'public' && !imageExtensions.includes(extensionOf(item.file.name))) {
        item.status = 'failed'
        item.message = '非图片只能私有上传'
        continue
      }
      item.status = 'uploading'
      try {
        await fetchUploadAttachment(item.file, {
          category: category.value.trim() || undefined,
          visibility: visibility.value
        })
        item.status = 'success'
        item.message = '附件已创建'
        succeeded++
      } catch (error) {
        item.status = 'failed'
        item.message = errorMessage(error)
      }
    }
    uploading.value = false
    const failedTotal = items.value.filter((item) => item.status === 'failed').length
    if (succeeded) {
      emit('uploaded')
      ElMessage[failedTotal ? 'warning' : 'success'](
        `成功上传 ${succeeded} 个文件${failedTotal ? `，${failedTotal} 个失败` : ''}`
      )
    } else if (failedTotal) {
      ElMessage.error(`${failedTotal} 个文件上传失败，请查看明细`)
    }
  }

  function tagType(status: ItemStatus): TagProps['type'] {
    if (status === 'success') return 'success'
    if (status === 'failed') return 'danger'
    if (status === 'uploading') return 'warning'
    return 'info'
  }

  function resultClass(status: ItemStatus) {
    if (status === 'success') return 'text-success'
    if (status === 'failed') return 'text-danger'
    return 'text-g-500'
  }

  function reset() {
    category.value = ''
    visibility.value = 'private'
    items.value = []
  }
</script>
