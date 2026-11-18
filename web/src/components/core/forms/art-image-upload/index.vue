<template>
  <div>
    <div
      class="image-upload-card grid size-[148px] grid-rows-[30px_1fr_30px] rounded-md border border-dashed"
    >
      <button
        type="button"
        class="image-upload-action rounded-b-2xl border-b border-dashed text-xs"
        :disabled="disabled || !allowLibrary"
        :title="allowLibrary ? '从附件库选择图片' : '需要附件查看权限'"
        @click="libraryVisible = true"
      >
        选择
      </button>
      <button
        type="button"
        class="image-upload-action grid min-h-0 place-items-center"
        aria-label="上传本地图片"
        :disabled="disabled"
        @click="fileInput?.click()"
      >
        <img
          v-if="previewUrl"
          :src="previewUrl"
          alt="图片预览"
          class="size-full min-h-0 object-contain"
          @error="failedPreview = previewUrl"
        />
        <ElIcon v-else :size="26" class="text-g-500"><Plus /></ElIcon>
      </button>
      <input
        ref="fileInput"
        type="file"
        hidden
        :accept="accept"
        :disabled="disabled"
        @change="selectLocal"
      />
      <button
        type="button"
        class="image-upload-action rounded-t-2xl border-t border-dashed text-xs"
        :disabled="disabled"
        @click="readClipboard"
      >
        截图上传
      </button>
    </div>
    <p class="m-0 mt-1 text-xs leading-5 text-g-500"
      >JPG、PNG、WebP、GIF，最大 {{ maxSizeMb }} MB</p
    >

    <ImageLibraryDialog
      v-if="allowLibrary"
      v-model:visible="libraryVisible"
      :max-bytes="maxBytes"
      @select="selectImage"
    />
    <ElDialog
      v-model="pasteVisible"
      title="截图上传"
      width="min(420px, calc(100% - 32px))"
      append-to-body
      align-center
      destroy-on-close
      @opened="pasteInput?.focus()"
      @paste.prevent="pasteImage"
    >
      <p class="m-0 mb-3 text-sm">复制图片后，将图片粘贴到下方（Ctrl+V 或 ⌘V）。</p>
      <ElInput
        ref="pasteInput"
        type="textarea"
        :rows="3"
        aria-label="粘贴图片"
        placeholder="在此粘贴图片"
      />
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { Plus } from '@element-plus/icons-vue'
  import { ElMessage, type InputInstance } from 'element-plus'
  import ImageLibraryDialog from './image-library-dialog.vue'

  const props = withDefaults(
    defineProps<{
      src?: string | null
      disabled?: boolean
      allowLibrary?: boolean
      maxSizeMb?: number
    }>(),
    { maxSizeMb: 2 }
  )
  const file = defineModel<File>()
  const fileInput = ref<HTMLInputElement>()
  const pasteInput = ref<InputInstance>()
  const libraryVisible = ref(false)
  const pasteVisible = ref(false)
  const localUrl = ref('')
  const failedPreview = ref('')
  const maxBytes = computed(() => props.maxSizeMb * 1024 * 1024)
  const imageTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
  const accept = imageTypes.join(',')
  let clipboardRequest = 0

  const previewUrl = computed(() => {
    const url = localUrl.value || props.src || ''
    return url === failedPreview.value ? '' : url
  })

  watch(
    file,
    (image, _, onCleanup) => {
      const url = image ? URL.createObjectURL(image) : ''
      localUrl.value = url
      if (url) onCleanup(() => URL.revokeObjectURL(url))
    },
    { immediate: true }
  )

  function selectImage(image: File) {
    if (props.disabled) return false
    if (!imageTypes.includes(image.type)) {
      ElMessage.warning('请选择 JPG、PNG、WebP 或 GIF 图片')
      return false
    }
    if (image.size > maxBytes.value) {
      ElMessage.warning(`图片不能超过 ${props.maxSizeMb} MB`)
      return false
    }
    file.value = image
    return true
  }

  function selectLocal(event: Event) {
    const input = event.currentTarget as HTMLInputElement
    const image = input.files?.[0]
    input.value = ''
    if (image) selectImage(image)
  }

  async function readClipboard() {
    const requestId = ++clipboardRequest
    pasteVisible.value = true
    try {
      const items = await navigator.clipboard?.read?.()
      for (const item of items ?? []) {
        const type = item.types.find((mime) => imageTypes.includes(mime))
        if (!type) continue
        const blob = await item.getType(type)
        if (requestId !== clipboardRequest || !pasteVisible.value) return
        const extension = type === 'image/jpeg' ? 'jpg' : type.split('/')[1]
        if (selectImage(new File([blob], `paste-${Date.now()}.${extension}`, { type }))) {
          pasteVisible.value = false
        }
        return
      }
    } catch {
      // Keep the keyboard paste dialog open when Clipboard API access is denied.
    }
  }

  function pasteImage(event: ClipboardEvent) {
    const image = Array.from(event.clipboardData?.files ?? []).find((item) =>
      item.type.startsWith('image/')
    )
    if (!image) {
      ElMessage.warning('剪贴板中没有图片，请先复制图片或截图')
      return
    }
    if (selectImage(image)) pasteVisible.value = false
  }
</script>

<style scoped>
  .image-upload-card {
    background: var(--el-fill-color-lighter);
    border-color: var(--el-border-color-darker);
  }

  .image-upload-action {
    border-color: var(--el-border-color);
    cursor: pointer;
  }

  .image-upload-action:enabled:hover {
    background: var(--el-fill-color);
    color: var(--el-color-primary);
  }

  .image-upload-action:focus-visible {
    outline: 2px solid var(--el-color-primary);
    outline-offset: -2px;
  }

  .image-upload-action:disabled {
    color: var(--el-text-color-disabled);
    cursor: not-allowed;
  }
</style>
