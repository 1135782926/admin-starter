<template>
  <ElDialog
    v-model="visible"
    :title="attachment?.title || '图片预览'"
    width="min(960px, calc(100% - 32px))"
    align-center
    destroy-on-close
    @closed="reset"
  >
    <div v-loading="loading" class="flex min-h-48 items-center justify-center">
      <ElAlert
        v-if="failed"
        title="图片地址获取失败，请关闭后重试。"
        type="error"
        :closable="false"
        class="w-full"
      />
      <img
        v-else-if="imageUrl"
        :src="imageUrl"
        :alt="attachment?.title || '附件图片'"
        class="max-h-[70vh] max-w-full object-contain"
      />
    </div>
    <p v-if="expiresAt" class="mb-0 mt-3 text-center text-xs text-g-500">
      私有图片临时地址有效期至 {{ expiresAt }}，请勿分享该地址。
    </p>
  </ElDialog>
</template>

<script setup lang="ts">
  import { fetchGetAttachmentTemporaryUrl } from '@/api/attachment'

  const props = defineProps<{ attachment?: Api.Attachment.Record }>()
  const visible = defineModel<boolean>('visible', { required: true })
  const loading = ref(false)
  const failed = ref(false)
  const imageUrl = ref('')
  const expiresAt = ref('')
  let requestId = 0

  watch(visible, async (open) => {
    const id = ++requestId
    if (!open || !props.attachment?.is_image) return
    failed.value = false
    expiresAt.value = ''
    imageUrl.value = props.attachment.visibility === 'public' ? props.attachment.url || '' : ''
    if (imageUrl.value) return
    loading.value = true
    try {
      const data = await fetchGetAttachmentTemporaryUrl(props.attachment.id, 'inline')
      if (id !== requestId || !visible.value) return
      imageUrl.value = data.url
      expiresAt.value = data.expires_at
    } catch {
      if (id === requestId) failed.value = true
    } finally {
      if (id === requestId) loading.value = false
    }
  })

  function reset() {
    requestId++
    loading.value = false
    failed.value = false
    imageUrl.value = ''
    expiresAt.value = ''
  }
</script>
